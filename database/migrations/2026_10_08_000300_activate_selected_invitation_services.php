<?php

use App\Features\Subscription\Services\InvitationServiceCatalogue;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(InvitationServiceCatalogue::class)->prepare(apply: true);
    }

    public function down(): void
    {
        // Restoring unknown prior catalogue flags or removed records would invent history.
        throw new RuntimeException('Retour arrière catalogue refusé : conservez les contrats et utilisez une correction ciblée.');
    }
};
