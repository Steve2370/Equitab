<?php

namespace App\Console\Commands;

use App\Features\Payment\Services\PaymentService;
use App\Models\GroupMember;
use Illuminate\Console\Command;
use Stripe\StripeClient;

class ReconcilePendingPayments extends Command
{
    protected $signature = 'equitab:reconcile-payments {--dry-run : N\'écrit rien, affiche seulement ce qui serait fait}';

    protected $description = "Filet de sécurité: réactive les membres bloqués 'en attente' dont l'abonnement Stripe est en fait actif/payé (webhook manqué ou confirmation front-end silencieusement échouée)";

    public function handle(PaymentService $paymentService): int
    {
        $stripe = new StripeClient(config('services.stripe.secret'));
        $dryRun = (bool) $this->option('dry-run');

        $stuckMembers = GroupMember::with(['group.subscription', 'user'])
            ->where('status', 'pending_payment')
            ->whereNotNull('stripe_subscription_id')
            ->get();

        if ($stuckMembers->isEmpty()) {
            $this->info('Aucun membre bloqué "en attente" avec un abonnement Stripe associé.');
            return self::SUCCESS;
        }

        $this->info("{$stuckMembers->count()} membre(s) en attente à vérifier auprès de Stripe...");

        $fixed = 0;

        foreach ($stuckMembers as $member) {
            try {
                $subscription = $stripe->subscriptions->retrieve($member->stripe_subscription_id, [
                    'expand' => ['latest_invoice.payment_intent'],
                ]);
            } catch (\Exception $e) {
                $this->warn("  [skip] membre #{$member->id} ({$member->user->email}) — Stripe: {$e->getMessage()}");
                continue;
            }

            $invoice = $subscription->latest_invoice;
            $paymentIntentStatus = $invoice?->payment_intent?->status ?? null;
            $invoiceStatus = $invoice?->status ?? null;

            $isReallyPaid = $subscription->status === 'active'
                || $paymentIntentStatus === 'succeeded'
                || $invoiceStatus === 'paid';

            if (! $isReallyPaid) {
                $this->line("  [ok] membre #{$member->id} ({$member->user->email}) — statut Stripe: {$subscription->status}, réellement en attente.");
                continue;
            }

            $amount = $invoice?->amount_paid ?? 0;
            $paymentIntentId = $invoice?->payment_intent?->id;

            if ($dryRun) {
                $this->warn("  [dry-run] membre #{$member->id} ({$member->user->email}) serait activé — groupe #{$member->group_id}, montant {$amount}");
                $fixed++;
                continue;
            }

            $paymentService->activateMemberAndRecordPayment(
                member: $member,
                amountPaid: $amount,
                currency: strtoupper($subscription->currency),
                paymentIntentId: $paymentIntentId,
            );

            $this->info("  [fixé] membre #{$member->id} ({$member->user->email}) réactivé — groupe #{$member->group_id}, montant {$amount}");
            $fixed++;
        }

        $this->info("Terminé — {$fixed} membre(s) " . ($dryRun ? 'à corriger' : 'corrigé(s)') . " sur {$stuckMembers->count()}.");

        return self::SUCCESS;
    }
}
