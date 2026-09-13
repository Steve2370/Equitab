<?php

namespace App\Jobs;

use App\Models\Group;
use App\Models\Payment;
use App\Models\User;
use App\Mail\AutoRefundProcessed;
use Illuminate\Support\Facades\Mail;
use App\Features\Payment\Services\PaymentService;
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
    public function handle(PaymentService $paymentService): void
    {
        $payment = Payment::find($this->paymentId);
        $group = Group::find($this->groupId);
        $user = User::find($this->userId);

        if (! $payment || ! $group || ! $user) return;
        if ($payment->status === 'refunded') return;
        if ($group->credential_email || $group->credential_password) return;

        try {
            // Délègue à PaymentService::refundPayment(), point d'entrée
            // unique partagé avec AdminController::resolveDispute() : ne
            // marque "refunded" qu'après confirmation réelle de Stripe,
            // conserve l'ID du refund, annule l'abonnement du membre.
            $paymentService->refundPayment($payment, 'auto_no_credentials');

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
