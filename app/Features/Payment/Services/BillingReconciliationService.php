<?php

namespace App\Features\Payment\Services;

use App\Mail\AutoRefundProcessed;
use App\Models\Dispute;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Resumes durable intentions; provider state remains owned by PaymentService. */
final class BillingReconciliationService
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly SubscriptionCancellationService $cancellations,
        private readonly BillingOperationLock $lock,
        private readonly CredentialsCheckScheduler $checks,
    ) {}

    public function refund(Payment $payment, string $reason): Payment
    {
        try {
            $result = $this->payments->refundPayment($payment, $reason);
            if ($result->status === 'refunded') {
                $this->notifyRefund($result);
            }

            return $result;
        } catch (Throwable) {
            Log::warning('Remboursement ou notification à reprendre.', ['payment_id' => $payment->id]);
            // Queue logs must not expose a provider exception or its "previous".
            throw new BillingUnavailable;
        }
    }

    /** @return array{cancellations:int, subscriptions:int, refunds:int, checks:int, errors:int} */
    public function reconcile(bool $dryRun = false): array
    {
        $result = ['cancellations' => 0, 'subscriptions' => 0, 'refunds' => 0, 'checks' => 0, 'errors' => 0];
        $cancellations = GroupMember::whereNotNull('cancellation_requested_at')
            ->where(fn ($query) => $query->whereNull('subscription_status')->orWhere('subscription_status', '!=', 'canceled'));
        // A missed renewal/cancellation webhook also leaves ACTIVE memberships
        // stale. Poll every live entitlement, not only initial checkout failures.
        $subscriptions = GroupMember::whereIn('status', ['pending_payment', 'active', 'suspended'])
            ->whereNull('cancellation_requested_at')->whereNotNull('stripe_subscription_id');
        $refunds = DB::table('payment_refund_attempts')->where(function ($query): void {
            $query->whereIn('status', ['requested', 'pending'])
                ->orWhere(fn ($query) => $query->where('status', 'succeeded')->whereNull('notified_at'));
        });
        // Only explicit durable intentions, never a retroactive legacy backfill.
        $checks = Payment::where('status', 'completed')->where('amount', '>', 0)
            ->whereNotNull('stripe_invoice_id')->whereNotNull('credentials_check_due_at')
            ->whereNull('credentials_check_queued_at');
        if ($dryRun) {
            return [...$result, 'cancellations' => $cancellations->count(),
                'subscriptions' => $subscriptions->count(), 'refunds' => $refunds->count(), 'checks' => $checks->count()];
        }
        foreach ($cancellations->lazyById() as $member) {
            $result['cancellations']++;
            try {
                if (! $this->cancellations->request($member)) {
                    $result['errors']++;
                }
            } catch (Throwable) {
                $result['errors']++;
                Log::warning('Annulation à reprendre.', ['member_id' => $member->id]);
            }
        }
        foreach ($subscriptions->lazyById() as $member) {
            $result['subscriptions']++;
            try {
                $this->payments->synchronizeSubscription($member->stripe_subscription_id);
            } catch (Throwable) {
                $result['errors']++;
                Log::warning('Abonnement à rapprocher.', ['member_id' => $member->id]);
            }
        }
        foreach ($refunds->lazyById(100, 'payment_id') as $attempt) {
            $result['refunds']++;
            try {
                $this->refund(Payment::findOrFail($attempt->payment_id), $attempt->reason);
            } catch (Throwable) {
                $result['errors']++;
                Log::warning('Remboursement à rapprocher.', ['payment_id' => $attempt->payment_id]);
            }
        }
        foreach ($checks->lazyById(100) as $payment) {
            $result['checks']++;
            try {
                $this->checks->enqueue($payment);
            } catch (Throwable) {
                $result['errors']++;
                Log::warning('Vérification des accès à planifier.', ['payment_id' => $payment->id]);
            }
        }

        return $result;
    }

    private function notifyRefund(Payment $payment): void
    {
        $this->lock->run('refund-notification:'.$payment->id, function (Closure $assertOwned) use ($payment): void {
            $payment = $payment->fresh();
            $attempt = DB::table('payment_refund_attempts')->where('payment_id', $payment->id)->first();
            if ($payment->status !== 'refunded' || ! $attempt || $attempt->notified_at) {
                return;
            }
            $group = Group::withTrashed()->with('subscription')->findOrFail($payment->group_id);
            $user = User::withTrashed()->findOrFail($payment->user_id);
            $payment->setRelation('group', $group);
            $assertOwned();
            // Outside SQL. Failure leaves notified_at empty. A crash after mail
            // delivery may duplicate the email on retry, never the refund.
            Mail::to($user->email)->send(new AutoRefundProcessed($payment, $user));
            DB::transaction(function () use ($payment, $group, $attempt, $assertOwned): void {
                $assertOwned();
                $claimed = DB::table('payment_refund_attempts')->where('payment_id', $payment->id)
                    ->whereNull('notified_at')->update(['notified_at' => now()]);
                if ($claimed !== 1) {
                    return;
                }
                if ($attempt->reason === 'auto_no_credentials') {
                    User::withTrashed()->whereKey($group->owner_id)->increment('disputed_payments_count');
                }
                Dispute::where('payment_id', $payment->id)->whereIn('status', ['open', 'under_review'])
                    ->update(['status' => 'resolved_refund', 'resolved_at' => now()]);
            });
        });
    }
}
