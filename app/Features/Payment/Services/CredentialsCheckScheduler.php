<?php

namespace App\Features\Payment\Services;

use App\Jobs\CheckCredentialsProvided;
use App\Models\Payment;
use Closure;

final class CredentialsCheckScheduler
{
    public function __construct(private readonly BillingOperationLock $lock) {}

    public function enqueue(Payment $payment): void
    {
        $this->lock->run('credentials-check:'.$payment->id, function (Closure $assertOwned) use ($payment): void {
            $current = $payment->fresh();
            if ($current->status !== 'completed' || $current->amount <= 0
                || ! $current->credentials_check_due_at || $current->credentials_check_queued_at) {
                return;
            }
            // This durable due date is written with the payment. A queue outage
            // leaves it available to the reconciler. Crash-after-send can enqueue
            // twice; the refund service and job are deliberately idempotent.
            $assertOwned();
            CheckCredentialsProvided::dispatch($current->id, $current->group_id, $current->user_id)
                ->delay($current->credentials_check_due_at);
            $assertOwned();
            $current->update(['credentials_check_queued_at' => now()]);
        });
    }
}
