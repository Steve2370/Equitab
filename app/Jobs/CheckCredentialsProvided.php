<?php

namespace App\Jobs;

use App\Features\Group\Services\ServiceAccessDelivery;
use App\Features\Payment\Services\BillingOperationLock;
use App\Features\Payment\Services\BillingReconciliationService;
use App\Models\Group;
use App\Models\Payment;
use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class CheckCredentialsProvided implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private readonly int $paymentId,
        private readonly int $groupId,
        private readonly int $userId,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(BillingReconciliationService $billing): void
    {
        // Serialize owner delivery with this decision. A durable refund already
        // started always wins; reading/opening the access is never a condition.
        app(BillingOperationLock::class)->run('access-delivery:'.$this->groupId, function (Closure $assertOwned) use ($billing): void {
            $this->check($billing, $assertOwned);
        });
    }

    private function check(BillingReconciliationService $billing, Closure $assertOwned): void
    {
        $payment = Payment::find($this->paymentId);
        if (! $payment || $payment->group_id !== $this->groupId || $payment->user_id !== $this->userId) {
            return;
        }
        $attempt = DB::table('payment_refund_attempts')->where('payment_id', $payment->id)->first();
        if ($attempt) {
            // Continue a durable request even if credentials arrive later.
            $assertOwned();
            $billing->refund($payment, $attempt->reason);

            return;
        }
        $group = Group::withTrashed()->find($this->groupId);
        if (! $group || $payment->status !== 'completed'
            || app(ServiceAccessDelivery::class)->providedFor($payment, $group)) {
            return;
        }
        // Failure remains retryable; the scheduler also resumes durable attempts.
        $assertOwned();
        $billing->refund($payment, 'auto_no_credentials');
    }
}
