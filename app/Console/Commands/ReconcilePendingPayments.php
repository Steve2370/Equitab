<?php

namespace App\Console\Commands;

use App\Features\Payment\Services\BillingReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcilePendingPayments extends Command
{
    protected $signature = 'equitab:reconcile-payments {--dry-run : Liste les intentions locales sans écrire ni appeler Stripe}';

    protected $description = 'Rapproche les abonnements en attente, actifs ou suspendus et reprend annulations, remboursements, notifications et vérifications des accès non terminés';

    public function handle(BillingReconciliationService $billing): int
    {
        try {
            $result = $billing->reconcile((bool) $this->option('dry-run'));
        } catch (Throwable) {
            Log::error('Réconciliation des paiements indisponible.');
            $this->error('Réconciliation indisponible ; aucune réussite ne peut être confirmée.');

            return self::FAILURE;
        }
        $this->info(sprintf('%s — abonnements : %d ; annulations : %d ; remboursements/notifications : %d ; vérifications des accès : %d.',
            $this->option('dry-run') ? 'Intentions à vérifier (aucun appel Stripe)' : 'Intentions examinées',
            $result['subscriptions'], $result['cancellations'], $result['refunds'], $result['checks']));
        if ($result['errors'] > 0) {
            $this->error($result['errors'].' opération(s) non terminée(s), conservée(s) pour reprise.');

            return self::FAILURE;
        }
        $this->info('Les opérations encore en attente restent suivies ; seuls les états confirmés font foi.');

        return self::SUCCESS;
    }
}
