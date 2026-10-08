<?php

namespace App\Features\Group\Services;

use App\Features\Group\Repositories\Contracts\GroupRepositoryInterface;
use App\Features\Payment\Contracts\PaymentGatewayInterface;
use App\Features\Payment\Services\BillingOperationLock;
use App\Features\Payment\Services\BillingUnavailable;
use App\Features\Payment\Services\MembershipState;
use App\Features\Payment\Services\OwnerOnboardingService;
use App\Features\Payment\Services\SubscriptionCancellationService;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\StripePrice;
use App\Models\Subscription;
use App\Models\User;
use App\Support\BillingCurrencies;
use App\Support\Currency;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class GroupService
{
    public function __construct(
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly PaymentGatewayInterface $gateway,
        private readonly OwnerPublicationEligibility $eligibility,
        private readonly OwnerOnboardingService $onboarding,
        private readonly GroupAccess $access,
        private readonly BillingOperationLock $billingLock,
        private readonly SubscriptionCancellationService $cancellations,
        private readonly MembershipState $memberships,
        private readonly GroupInvitationLinks $invitationLinks,
        private readonly ServiceAccessPublication $serviceAccess,
    ) {}

    public function create(User $owner, array $data): Group
    {
        $this->eligibility->assertCanPrepare($owner);
        $subscription = Subscription::where('is_active', true)->findOrFail($data['subscription_id']);
        $this->serviceAccess->validate($subscription, $data);
        if (($data['max_members'] ?? 0) > $subscription->max_members) {
            throw ValidationException::withMessages(['max_members' => 'Le nombre de membres dépasse la capacité de ce service.']);
        }
        $data['currency'] = Currency::normalize($data['currency'] ?? $subscription->currency);
        BillingCurrencies::assertEnabled($data['currency']);
        $this->onboarding->refresh($owner);
        $this->eligibility->assertCanPublish($owner->fresh());

        return DB::transaction(function () use ($owner, $data) {
            Subscription::where('is_active', true)->whereKey($data['subscription_id'])->sharedLock()->firstOrFail();
            $group = $this->groupRepository->create([
                ...$data,
                ...$this->invitationLinks->attributes($data['visibility'] ?? 'public'),
                'owner_id' => $owner->id,
                'current_members' => 1,
                'status' => 'open',
                'uuid' => Str::uuid(),
            ]);

            $group->load('subscription');

            $stripeData = $this->gateway->createProduct($group);

            // A suspension or a webhook restriction during the remote call wins.
            $currentOwner = User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
            $this->eligibility->assertCanPublish($currentOwner);

            StripePrice::create([
                'group_id' => $group->id,
                'stripe_price_id' => null,
                'stripe_product_id' => $stripeData['product_id'],
                'unit_amount' => $group->total_price,
                'currency' => $group->currency,
            ]);

            $group->members()->create([
                'user_id' => $owner->id,
                'role' => 'owner',
                'status' => 'active',
                'share_amount' => $group->calculateCurrentPricePerMember(),
                'joined_at' => now(),
            ]);

            return $group;
        });
    }

    /** Reserve a seat without granting service access or charging the member. */
    public function join(User $user, Group $group, ?string $inviteToken = null): void
    {
        $this->billingLock->run('group:'.$group->id, function (Closure $assertOwned) use ($user, $group, $inviteToken): void {
            DB::transaction(function () use ($user, $group, $inviteToken, $assertOwned): void {
                $current = Group::lockForUpdate()->findOrFail($group->id);
                $account = User::lockForUpdate()->findOrFail($user->id);
                $member = $current->members()->where('user_id', $account->id)->first();
                $this->access->authorizeSubscription($account, $current, $inviteToken, $member);
                abort_if($member !== null, 409, 'Vous êtes déjà membre de ce groupe.');
                BillingCurrencies::assertEnabled($current->currency);
                $assertOwned();

                $current->members()->create([
                    'user_id' => $account->id,
                    'role' => 'member',
                    'status' => 'pending_payment',
                    'share_amount' => $current->calculatePricePerMemberIfJoined(),
                    'joined_at' => now(),
                    'next_payment_at' => now()->addDays(3),
                ]);
                $current->increment('current_members');
                if ($current->isFull()) {
                    $current->update(['status' => 'full']);
                }
            });
        });
    }

    public function leave(User $user, Group $group): void
    {
        $this->assertOutsideTransaction();
        abort_if($group->owner_id === $user->id, 403, 'Le propriétaire doit fermer son groupe.');
        $member = $group->members()->where('user_id', $user->id)->where('role', 'member')->firstOrFail();
        $this->cancellations->request($member);
    }

    /** All cancellation requests commit before the first remote call. */
    public function close(User $owner, Group $group, bool $delete = false): bool
    {
        $this->assertOutsideTransaction();
        $memberIds = $this->billingLock->run('group:'.$group->id, function (Closure $assertOwned) use ($owner, $group, $delete): array {
            return DB::transaction(function () use ($owner, $group, $delete, $assertOwned): array {
                $current = Group::lockForUpdate()->findOrFail($group->id);
                $account = User::lockForUpdate()->findOrFail($owner->id);
                abort_unless($current->owner_id === $account->id && $account->canAccessAccount() && $account->hasVerifiedEmail(), 403);
                $assertOwned();
                $current->update(['status' => 'closed']);

                $members = $current->members()->where('role', 'member')
                    ->where(function ($query): void {
                        $query->whereIn('status', ['active', 'pending_payment', 'suspended'])
                            ->orWhereNotNull('cancellation_requested_at')
                            ->orWhereNotNull('stripe_subscription_id');
                    })->lockForUpdate()->get();
                foreach ($members as $member) {
                    $member->update(['cancellation_requested_at' => $member->cancellation_requested_at ?? now()]);
                    $terminal = in_array($member->subscription_status, ['canceled', 'incomplete_expired'], true);
                    $status = $terminal ? $member->subscription_status : ($member->stripe_subscription_id ? 'cancellation_pending' : 'canceled');
                    $this->memberships->revoke($member, $status, true);
                }
                if ($delete) {
                    $current->delete(); // Keep financial history and retry identifiers.
                }

                return $members->modelKeys();
            });
        });

        // request() acquires the same lock: never invoke it re-entrantly.
        $complete = true;
        foreach ($memberIds as $id) {
            try {
                $confirmed = $this->cancellations->request(GroupMember::findOrFail($id));
                $complete = $confirmed && $complete;
            } catch (BillingUnavailable) {
                $complete = false; // Durable request remains for reconciliation.
            }
        }

        return $complete;
    }

    public function update(User $owner, Group $group, array $data): Group
    {
        if (($data['status'] ?? null) === 'closed') {
            $this->close($owner, $group);
            unset($data['status']);
        }

        return $this->billingLock->run('group:'.$group->id, function (Closure $assertOwned) use ($owner, $group, $data): Group {
            return DB::transaction(function () use ($owner, $group, $data, $assertOwned): Group {
                $current = Group::lockForUpdate()->findOrFail($group->id);
                $account = User::lockForUpdate()->findOrFail($owner->id);
                abort_unless($current->owner_id === $account->id && $account->canAccessAccount() && $account->hasVerifiedEmail(), 403);
                abort_if($current->status === 'closed' && isset($data['status']) && $data['status'] !== 'closed', 409, 'Ce groupe est fermé.');
                $assertOwned();

                return $this->groupRepository->update($current, [
                    ...$data,
                    ...$this->invitationLinks->attributes(
                        $data['visibility'] ?? $current->visibility,
                        $current->visibility === 'public' ? null : $current->invite_token,
                    ),
                ]);
            });
        });
    }

    private function assertOutsideTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Group cancellation must run outside an existing database transaction.');
        }
    }
}
