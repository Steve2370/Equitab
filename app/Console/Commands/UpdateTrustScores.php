<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class UpdateTrustScores extends Command
{
    protected $signature = 'equitab:update-trust-scores';
    protected $description = 'Recalculer les scores de confiance de tous les propriétaires';

    public function handle(): void
    {
        User::whereHas('ownedGroups')->each(function (User $user) {
            $user->update(['trust_score' => $user->calculateTrustScore() / 100]);
            $this->info("Score mis à jour pour {$user->email}: {$user->trust_score}");
        });
    }
}
