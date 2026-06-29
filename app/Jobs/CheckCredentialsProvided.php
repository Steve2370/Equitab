<?php

namespace App\Jobs;

use App\Models\Group;
use App\Models\Payment;
use App\Models\User;
use App\Mail\AutoRefundProcessed;
use Illuminate\Support\Facades\Mail;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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
    public function handle(PaymentGatewayInterface $gateway): void
    {
        $payment = Payment::find($this->paymentId);
        $group = Group::find($this->groupId);
        $user = User::find($this->userId);

        if (! $payment || ! $group || ! $user) return;
        if ($payment->status === 'refunded') return;
        if ($group->credential_email || $group->credential_password) return;

        try {
            if ($payment->stripe_payment_intent_id) {
                $gateway->refundPayment($payment->stripe_payment_intent_id);
            }

            $payment->update([
                'status' => 'refunded',
                'refunded_at' => now(),
                'refund_reason' => 'auto_no_credentials',
            ]);

            $group->owner->increment('disputed_payments_count');

            Mail::to($user->email)
                ->send(new AutoRefundProcessed(
                    $payment->load('group.subscription'),
                    $user
                ));

            Log::info("Remboursement automatique — paiement #{$this->paymentId}");

        } catch (\Exception $e) {
            Log::error("Échec remboursement automatique #{$this->paymentId}: " . $e->getMessage());
        }
    }
}
