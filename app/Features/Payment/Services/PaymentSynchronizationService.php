<?php

namespace App\Features\Payment\Services;

use App\Features\Payment\Contracts\BillingReadGatewayInterface;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\User;
use App\Support\Currency;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PaymentSynchronizationService
{
    public function __construct(
        private readonly BillingReadGatewayInterface $gateway,
        private readonly BillingOperationLock $lock,
        private readonly MembershipState $memberships,
        private readonly CredentialsCheckScheduler $checks,
    ) {}

    public static function objectId(mixed $value): ?string
    {
        return is_string($value) ? $value : (is_array($value) ? ($value['id'] ?? null) : null);
    }

    public static function invoiceSubscription(array $invoice): ?string
    {
        $parent = $invoice['parent'] ?? null;

        return self::objectId(is_array($parent) && ($parent['type'] ?? null) === 'subscription_details'
            ? ($parent['subscription_details']['subscription'] ?? null)
            : ($invoice['subscription'] ?? null));
    }

    public static function assertCurrency(string $expected, mixed $actual): void
    {
        try {
            if (is_string($actual) && Currency::normalize($actual) === Currency::normalize($expected)) {
                return;
            }
        } catch (InvalidArgumentException) {
            // Unknown currencies require reconciliation, never a CAD fallback.
        }

        throw new BillingUnavailable;
    }

    public static function assertSubscriptionCurrency(array $subscription, string $currency): void
    {
        // Sparse cancellation snapshots and existing gateway doubles need not
        // carry prices. Every supplied monetary currency must still agree.
        if (array_key_exists('currency', $subscription)) {
            self::assertCurrency($currency, $subscription['currency']);
        }
        foreach ($subscription['items']['data'] ?? [] as $item) {
            if (is_array($item['price'] ?? null) && array_key_exists('currency', $item['price'])) {
                self::assertCurrency($currency, $item['price']['currency']);
            }
        }
        $invoice = $subscription['latest_invoice'] ?? null;
        if (is_array($invoice) && array_key_exists('currency', $invoice)) {
            self::assertCurrency($currency, $invoice['currency']);
        }
    }

    public function synchronize(string $subscriptionId, ?string $invoiceId = null): ?Payment
    {
        $member = GroupMember::where('stripe_subscription_id', $subscriptionId)->first();
        if (! $member) {
            return null;
        }
        [$payment, $created] = $this->lock->run('group:'.$member->group_id, function (Closure $assertOwned) use ($member, $subscriptionId, $invoiceId): array {
            $group = Group::withTrashed()->findOrFail($member->group_id);
            $currency = Currency::normalize($group->currency);
            $attempt = DB::table('subscription_attempts')->where('group_id', $member->group_id)->where('user_id', $member->user_id)->first();
            if ($attempt) {
                $parameters = json_decode(Crypt::decryptString($attempt->parameters), true, flags: JSON_THROW_ON_ERROR);
                self::assertCurrency($currency, $parameters['price']['currency'] ?? null);
            }
            $sub = $this->gateway->retrieveSubscription($subscriptionId);
            $customer = $member->stripe_customer_id ?? User::withTrashed()->find($member->user_id)?->stripe_customer_id;
            if (($sub['id'] ?? null) !== $subscriptionId || ! $customer || self::objectId($sub['customer'] ?? null) !== $customer) {
                throw new BillingUnavailable;
            }
            $status = $sub['status'] ?? null;
            if (! is_string($status)) {
                throw new BillingUnavailable;
            }
            $latestId = self::objectId($sub['latest_invoice'] ?? null);
            $requestedId = $invoiceId ?? $latestId;
            if ($status !== 'active' || ! $requestedId) {
                self::assertSubscriptionCurrency($sub, $currency);
                DB::transaction(function () use ($member, $status, $assertOwned): void {
                    $current = GroupMember::lockForUpdate()->findOrFail($member->id);
                    $assertOwned();
                    $terminal = in_array($status, ['canceled', 'incomplete_expired'], true);
                    if (! in_array($current->status, ['left', 'kicked'], true) || $terminal) {
                        $this->memberships->revoke($current, $status, $terminal);
                    }
                });

                return [null, false];
            }
            $invoice = $this->gateway->retrieveInvoice($requestedId);
            self::assertSubscriptionCurrency($sub, $currency);
            self::assertCurrency($currency, $sub['currency'] ?? null);
            self::assertCurrency($currency, $invoice['currency'] ?? null);
            if (($invoice['id'] ?? null) !== $requestedId || self::invoiceSubscription($invoice) !== $subscriptionId
                || self::objectId($invoice['customer'] ?? null) !== $customer) {
                throw new BillingUnavailable;
            }
            $isLatest = $requestedId === $latestId;
            $amount = $invoice['amount_paid'] ?? null;
            if (($invoice['status'] ?? null) !== 'paid' || ! is_int($amount) || $amount < 0 || ($invoice['amount_remaining'] ?? 0) !== 0) {
                if ($isLatest) {
                    DB::transaction(function () use ($member, $assertOwned): void {
                        $current = GroupMember::lockForUpdate()->findOrFail($member->id);
                        $assertOwned();
                        if (! in_array($current->status, ['left', 'kicked'], true)) {
                            $this->memberships->revoke($current, 'past_due', false);
                        }
                    });
                }

                return [null, false];
            }
            [$intentId, $refunded, $partialRefund] = $this->paymentProof($invoice, $customer, $amount);
            $periodEnd = $sub['items']['data'][0]['current_period_end'] ?? $sub['current_period_end'] ?? null;
            $periodStart = $invoice['period_start'] ?? null;
            $invoiceEnd = $invoice['period_end'] ?? null;
            if (! is_int($periodStart) || ! is_int($invoiceEnd) || ! is_int($periodEnd)) {
                throw new BillingUnavailable;
            }

            return DB::transaction(function () use ($member, $invoice, $sub, $amount, $intentId, $refunded, $partialRefund, $periodStart, $invoiceEnd, $periodEnd, $isLatest, $assertOwned): array {
                $group = Group::withTrashed()->lockForUpdate()->findOrFail($member->group_id);
                $current = GroupMember::lockForUpdate()->findOrFail($member->id);
                $user = User::withTrashed()->findOrFail($member->user_id);
                $assertOwned();
                self::assertCurrency($group->currency, $invoice['currency']);
                $payments = Payment::where('stripe_invoice_id', $invoice['id'])
                    ->when($intentId, fn ($query) => $query->orWhere('stripe_payment_intent_id', $intentId))
                    ->lockForUpdate()->get();
                if ($payments->count() > 1) {
                    throw new BillingUnavailable;
                }
                $payment = $payments->first();
                if ($payment && ($payment->group_id !== $member->group_id || $payment->user_id !== $member->user_id)) {
                    throw new BillingUnavailable;
                }
                if ($payment && $payment->stripe_invoice_id && $payment->stripe_invoice_id !== $invoice['id']) {
                    // Multi-invoice allocations are not supported by this
                    // single-payment ledger; never silently overwrite one.
                    throw new BillingUnavailable;
                }
                if ($payment) {
                    self::assertCurrency($group->currency, $payment->currency);
                    if ($payment->amount !== $amount
                        || ($payment->stripe_payment_intent_id && $payment->stripe_payment_intent_id !== $intentId)) {
                        throw new BillingUnavailable;
                    }
                }
                $created = ! $payment;
                if (! $payment) {
                    $payment = Payment::create([
                        'group_id' => $member->group_id, 'user_id' => $member->user_id, 'amount' => $amount,
                        'currency' => Currency::normalize($group->currency), 'status' => $refunded ? 'refunded' : 'completed',
                        'paid_at' => Carbon::createFromTimestamp($invoice['status_transitions']['paid_at'] ?? $invoiceEnd),
                        'due_date' => Carbon::createFromTimestamp($invoiceEnd),
                        'period_start' => Carbon::createFromTimestamp($periodStart), 'period_end' => Carbon::createFromTimestamp($invoiceEnd),
                        'platform_fee_amount' => (int) round($amount * 0.05),
                        'stripe_payment_intent_id' => $intentId, 'stripe_invoice_id' => $invoice['id'],
                        'credentials_check_due_at' => ! $refunded && $amount > 0 ? now()->addHours(48) : null,
                        'refunded_at' => $refunded ? now() : null,
                        'refund_reason' => $refunded ? 'stripe_refund' : null,
                    ]);
                    $user->increment('completed_payments_count');
                } elseif (! $payment->stripe_invoice_id) {
                    $payment->update(['stripe_invoice_id' => $invoice['id']]);
                }
                if ($refunded && $payment->status !== 'refunded') {
                    $payment->update(['status' => 'refunded', 'refunded_at' => now(), 'refund_reason' => 'stripe_refund']);
                }
                $refundRequested = DB::table('payment_refund_attempts')->where('payment_id', $payment->id)->exists();
                $terminal = in_array($current->status, ['left', 'kicked'], true) || $current->cancellation_requested_at;
                $accountBlocked = $user->trashed() || $user->isSuspended() || $user->status === 'banned' || ! $user->hasVerifiedEmail();
                $groupClosed = $group->trashed() || $group->status === 'closed';
                if ($isLatest && ($payment->status === 'refunded' || $partialRefund || $refundRequested || $groupClosed)) {
                    $current->update(['cancellation_requested_at' => $current->cancellation_requested_at ?? now()]);
                    $this->memberships->revoke($current, 'cancellation_pending', true);
                } elseif ($isLatest && ! $terminal && ! $accountBlocked && $periodEnd > now()->timestamp) {
                    $current->update([
                        'status' => 'active', 'subscription_status' => $sub['status'], 'current_period_end' => Carbon::createFromTimestamp($periodEnd),
                        'last_payment_at' => $payment->paid_at, 'next_payment_at' => Carbon::createFromTimestamp($periodEnd),
                    ]);
                } elseif ($isLatest && ! $terminal) {
                    $this->memberships->revoke($current, $sub['status'], false);
                }

                return [$payment, $created && $payment->status === 'completed' && $amount > 0];
            });
        });
        if ($payment) {
            $this->checks->enqueue($payment);
        }

        return $payment;
    }

    private function paymentProof(array $invoice, string $customer, int $amount): array
    {
        if ($amount === 0) {
            if (($invoice['amount_due'] ?? null) !== 0) {
                throw new BillingUnavailable;
            }

            return [null, false, false];
        }
        $intentId = self::objectId($invoice['payment_intent'] ?? null);
        if (! $intentId) {
            $payments = $this->gateway->invoicePayments($invoice['id']);
            $ids = [];
            $total = 0;
            foreach ($payments as $payment) {
                if (($payment['status'] ?? null) !== 'paid' || self::objectId($payment['invoice'] ?? null) !== $invoice['id']) {
                    throw new BillingUnavailable;
                }
                $id = self::objectId($payment['payment']['payment_intent'] ?? null);
                if (! $id || ! is_int($payment['amount_paid'] ?? null)) {
                    throw new BillingUnavailable;
                }
                $ids[] = $id;
                $total += $payment['amount_paid'];
            }
            $ids = array_values(array_unique($ids));
            // EquitAb currently accepts a single card payment per invoice.
            // Mixed / out-of-band settlements require explicit reconciliation.
            if (count($ids) !== 1 || $total !== $amount) {
                throw new BillingUnavailable;
            }
            $intentId = $ids[0];
        }
        $intent = $this->gateway->retrievePaymentIntent($intentId);
        self::assertCurrency($invoice['currency'], $intent['currency'] ?? null);
        if (($intent['id'] ?? null) !== $intentId || ($intent['status'] ?? null) !== 'succeeded'
            || self::objectId($intent['customer'] ?? null) !== $customer
            || ($intent['amount_received'] ?? -1) < $amount) {
            throw new BillingUnavailable;
        }
        $charge = $intent['latest_charge'] ?? null;
        if (! is_array($charge) || ! array_key_exists('refunded', $charge) || ! is_int($charge['amount_refunded'] ?? null)) {
            throw new BillingUnavailable;
        }

        return [$intentId, $charge['refunded'] === true, $charge['amount_refunded'] > 0];
    }
}
