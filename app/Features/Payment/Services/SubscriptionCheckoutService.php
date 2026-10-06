<?php

namespace App\Features\Payment\Services;

use App\Features\Group\Services\GroupAccess;
use App\Features\Payment\Contracts\BillingReadGatewayInterface;
use App\Features\Payment\Contracts\CheckoutGatewayInterface;
use App\Features\Payment\Contracts\PaymentGatewayInterface;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SubscriptionCheckoutService
{
    public function __construct(
        private readonly GroupAccess $access,
        private readonly BillingOperationLock $lock,
        private readonly CheckoutGatewayInterface $writer,
        private readonly BillingReadGatewayInterface $reader,
        private readonly BillingCustomerService $customers,
        private readonly PaymentGatewayInterface $accounts,
    ) {}

    public function start(User $payer, Group $group, string $methodId, ?string $inviteToken = null): array
    {
        // A durable attempt cannot be enclosed in an outer transaction which
        // would roll it back after an ambiguous external response.
        if (DB::transactionLevel() !== 0) {
            throw new BillingUnavailable;
        }

        return $this->lock->run('group:'.$group->id, function (Closure $assertOwned) use ($payer, $group, $methodId, $inviteToken): array {
            $group = $group->fresh(['subscription', 'owner', 'stripePrice']);
            $payer = $payer->fresh();
            abort_unless($group && $payer && $group->owner, 404);
            $member = $group->members()->where('user_id', $payer->id)->first();
            $this->access->authorizeSubscription($payer, $group, $inviteToken, $member);
            if ($member?->stripe_subscription_id) {
                return $this->reader->retrieveSubscription($member->stripe_subscription_id);
            }
            abort_if($group->owner->isSuspended() || $group->owner->status === 'banned', 403, 'Ce groupe est indisponible.');
            if (! $this->accounts->isAccountActive($group->owner)) {
                throw new BillingUnavailable;
            }
            $attempt = DB::table('subscription_attempts')->where('group_id', $group->id)->where('user_id', $payer->id)->first();
            if (! $attempt) {
                abort_unless($group->stripePrice?->stripe_product_id, 409, 'Le paiement de ce groupe n’est pas encore configuré.');
                $amount = (int) round($group->total_price / ($group->members()->where('status', 'active')->count() + 1));
                $parameters = [
                    'method' => $methodId, 'destination' => $group->owner->stripe_connect_account_id,
                    'anchor' => now()->addMonthNoOverflow()->startOfMonth()->timestamp,
                    'price' => ['unit_amount' => $amount, 'currency' => strtolower($group->subscription->currency),
                        'recurring' => ['interval' => 'month'], 'product' => $group->stripePrice->stripe_product_id],
                ];
                DB::transaction(function () use ($payer, $group, $member, $parameters, $assertOwned): void {
                    $locked = Group::lockForUpdate()->findOrFail($group->id);
                    $assertOwned();
                    if (! $member) {
                        $locked->members()->create([
                            'user_id' => $payer->id, 'role' => 'member', 'status' => 'pending_payment',
                            'share_amount' => $parameters['price']['unit_amount'], 'joined_at' => now(),
                        ]);
                        $locked->increment('current_members');
                        if ($locked->isFull()) {
                            $locked->update(['status' => 'full']);
                        }
                    }
                    DB::table('subscription_attempts')->insert([
                        'id' => (string) Str::uuid(), 'group_id' => $group->id, 'user_id' => $payer->id,
                        'parameters' => Crypt::encryptString(json_encode($parameters, JSON_THROW_ON_ERROR)), 'started_at' => now(),
                    ]);
                });
                $attempt = DB::table('subscription_attempts')->where('group_id', $group->id)->where('user_id', $payer->id)->first();
            }
            if (Carbon::parse($attempt->started_at)->lte(now()->subHours(23))) {
                throw new BillingUnavailable;
            }
            $parameters = json_decode(Crypt::decryptString($attempt->parameters), true, flags: JSON_THROW_ON_ERROR);
            $customerId = $this->customers->customer($payer);
            $this->writer->attachPaymentMethod($parameters['method'], $customerId);
            $priceId = $attempt->price_id;
            if (! $priceId) {
                $priceId = $this->writer->createPrice($parameters['price'], $attempt->id.':price');
                $assertOwned();
                DB::table('subscription_attempts')->where('id', $attempt->id)->update(['price_id' => $priceId]);
            }
            $assertOwned();
            $currentGroup = $group->fresh(['owner']);
            $currentPayer = $payer->fresh();
            abort_unless($currentGroup && $currentPayer && $currentGroup->owner, 403);
            $this->access->authorizeSubscription($currentPayer, $currentGroup, $inviteToken,
                $currentGroup->members()->where('user_id', $payer->id)->first());
            abort_unless($currentGroup->owner->canAccessAccount(), 403, 'Ce groupe est indisponible.');
            // Immutable arguments plus a durable key survive process death and
            // a lost response. Never start a new subscription on an uncertain retry.
            $raw = $this->writer->createSubscription([
                'customer' => $customerId, 'items' => [['price' => $priceId]],
                'application_fee_percent' => 5, 'transfer_data' => ['destination' => $parameters['destination']],
                'default_payment_method' => $parameters['method'], 'payment_behavior' => 'default_incomplete',
                'payment_settings' => ['save_default_payment_method' => 'on_subscription', 'payment_method_types' => ['card']],
                'billing_cycle_anchor' => $parameters['anchor'], 'proration_behavior' => 'create_prorations',
                'expand' => ['latest_invoice.confirmation_secret'],
                'metadata' => ['equitab_attempt' => $attempt->id, 'group_id' => (string) $group->id, 'user_id' => (string) $payer->id],
            ], $attempt->id.':subscription');
            if (! str_starts_with($raw['id'] ?? '', 'sub_') || ($raw['customer'] ?? null) !== $customerId) {
                throw new BillingUnavailable;
            }
            DB::transaction(function () use ($group, $payer, $raw, $customerId, $attempt, $parameters, $assertOwned): void {
                $member = GroupMember::where('group_id', $group->id)->where('user_id', $payer->id)->lockForUpdate()->firstOrFail();
                $assertOwned();
                if ($member->stripe_subscription_id && $member->stripe_subscription_id !== $raw['id']) {
                    throw new BillingUnavailable;
                }
                // Even if an admin revoked access during the request, retain the
                // external ID for cancellation. Do not overwrite the local status.
                $member->update([
                    'stripe_subscription_id' => $raw['id'], 'stripe_customer_id' => $customerId,
                    'stripe_subscription_item_id' => $raw['items']['data'][0]['id'] ?? null,
                    'subscription_status' => $raw['status'], 'share_amount' => $parameters['price']['unit_amount'],
                ]);
                DB::table('subscription_attempts')->where('id', $attempt->id)->update(['subscription_id' => $raw['id']]);
                $currentUser = User::withTrashed()->findOrFail($payer->id);
                $currentGroup = Group::withTrashed()->findOrFail($group->id);
                if (! $currentUser->canAccessAccount() || $currentUser->trashed() || $currentGroup->trashed()
                    || $currentGroup->status === 'closed' || in_array($member->status, ['left', 'kicked'], true)) {
                    $member->update(['cancellation_requested_at' => $member->cancellation_requested_at ?? now()]);
                }
            });

            return $raw;
        });
    }
}
