<?php

namespace App\Console\Commands;

use App\Features\Subscription\Services\InvitationServiceCatalogue;
use Illuminate\Console\Command;
use RuntimeException;

class PrepareInvitationServices extends Command
{
    protected $signature = 'equitab:prepare-invitation-services
        {--dry-run : Inspecter sans écrire (comportement par défaut)}
        {--apply : Activer Dropbox et NordPass, retirer Bitwarden en préservant son historique}';

    protected $description = 'Appliquer le catalogue Dropbox Family / NordPass Family sans Bitwarden';

    public function handle(InvitationServiceCatalogue $catalogue): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Choisissez --dry-run ou --apply, pas les deux.');

            return self::INVALID;
        }

        try {
            $rows = $catalogue->prepare(apply: (bool) $this->option('apply'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Service', 'Clé', 'Action', 'Identifiant'], $rows);
        $this->info($this->option('apply')
            ? 'Dropbox Family et NordPass Family actifs. Bitwarden retiré ; contrats existants conservés.'
            : 'Simulation : aucune écriture. Utilisez --apply pour appliquer ce choix au catalogue.');
        $this->comment('6 personnes au total, propriétaire compris. Aucun tarif ni droit de commercialisation présumé.');

        return self::SUCCESS;
    }
}
