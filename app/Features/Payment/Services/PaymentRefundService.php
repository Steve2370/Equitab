<?php

namespace App\Features\Payment\Services;

use App\Features\Payment\Contracts\BillingReadGatewayInterface;
use App\Features\Payment\Contracts\PaymentGatewayInterface;
use App\Models\GroupMember;
use App\Models\Payment;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class PaymentRefundService
{
    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
        private readonly BillingReadGatewayInterface $reader,
        private readonly BillingOperationLock $lock,
        private readonly SubscriptionCancellationService $cancellations,
    ) {}

    public function refund(Payment $payment, string $reason): Payment
    {
        if (DB::transactionLevel() !== 0) {
            throw new BillingUnavailable;
        }

        return $this->lock->run('refund:'.$payment->id, function (Closure $assertOwned) use ($payment, $reason): Payment {
            $payment = $payment->fresh();
            if (! $payment->stripe_payment_intent_id) {
                throw new RuntimeException('Ce paiement doit être rapproché avec Stripe avant tout remboursement.');
            }
            $member = GroupMember::where('group_id', $payment->group_id)->where('user_id', $payment->user_id)->first();
            if ($payment->status === 'refunded') {
                if ($member) {
                    $this->cancellations->request($member);
                }

                return $payment;
            }
            abort_unless($payment->status === 'completed', 409, 'Ce paiement ne peut pas être remboursé.');
            $attempt = DB::table('payment_refund_attempts')->where('payment_id', $payment->id)->first();
            if (! $attempt) {
                DB::table('payment_refund_attempts')->insert([
                    'payment_id' => $payment->id, 'idempotency_key' => (string) Str::uuid(),
                    'status' => 'requested', 'reason' => $reason, 'started_at' => now(),
                ]);
                $attempt = DB::table('payment_refund_attempts')->where('payment_id', $payment->id)->first();
            }
            // The cancellation request survives independently of refund success.
            if ($member) {
                $this->cancellations->request($member);
            }
            if ($attempt->refund_id) {
                $raw = $this->reader->retrieveRefund($attempt->refund_id);
                if (($raw['id'] ?? null) !== $attempt->refund_id
                    || PaymentSynchronizationService::objectId($raw['payment_intent'] ?? null) !== $payment->stripe_payment_intent_id) {
                    throw new BillingUnavailable;
                }
                $result = ['refund_id' => $raw['id'], 'status' => $raw['status'] ?? null];
            } else {
                if (Carbon::parse($attempt->started_at)->lte(now()->subHours(23))) {
                    throw new BillingUnavailable;
                }
                $result = $this->gateway->refundPayment($payment->stripe_payment_intent_id, null, $attempt->idempotency_key);
            }
            $status = $result['status'] ?? null;
            $refundId = $result['refund_id'] ?? null;
            if (! in_array($status, ['succeeded', 'pending', 'failed', 'canceled', 'requires_action'], true) || ! is_string($refundId) || ! str_starts_with($refundId, 're_')) {
                throw new BillingUnavailable;
            }
            DB::transaction(function () use ($payment, $attempt, $status, $refundId, $assertOwned): void {
                $current = Payment::lockForUpdate()->findOrFail($payment->id);
                $assertOwned();
                DB::table('payment_refund_attempts')->where('payment_id', $payment->id)->update(['status' => $status, 'refund_id' => $refundId]);
                $current->update(['stripe_refund_id' => $refundId]);
                if ($status === 'succeeded') {
                    $current->update(['status' => 'refunded', 'refunded_at' => now(), 'refund_reason' => $attempt->reason]);
                }
            });
            if (in_array($status, ['failed', 'canceled', 'requires_action'], true)) {
                throw new RuntimeException('Le remboursement nécessite une vérification ; il n’est pas confirmé.');
            }

            return $payment->fresh();
        });
    }

    public function synchronizeIntent(string $intentId): void
    {
        $payment = Payment::where('stripe_payment_intent_id', $intentId)->first();
        if (! $payment) {
            return;
        }
        $attempt = DB::table('payment_refund_attempts')->where('payment_id', $payment->id)->first();
        if ($attempt) {
            $this->refund($payment, $attempt->reason);

            return;
        }
        $intent = $this->reader->retrievePaymentIntent($intentId);
        if (($intent['id'] ?? null) !== $intentId) {
            throw new BillingUnavailable;
        }
        $charge = $intent['latest_charge'] ?? null;
        if (! is_array($charge) || ! is_int($charge['amount_refunded'] ?? null) || ! is_bool($charge['refunded'] ?? null)) {
            throw new BillingUnavailable;
        }
        if ($charge['amount_refunded'] === 0) {
            return;
        }
        $this->lock->run('refund:'.$payment->id, function (Closure $assertOwned) use ($payment, $charge): void {
            $assertOwned();
            if ($charge['refunded']) {
                $payment->update(['status' => 'refunded', 'refunded_at' => $payment->refunded_at ?? now(), 'refund_reason' => 'stripe_refund']);
            }
            $member = GroupMember::where('group_id', $payment->group_id)->where('user_id', $payment->user_id)->first();
            if ($member) {
                $this->cancellations->request($member);
            }
        });
    }
}
