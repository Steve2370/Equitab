<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\RecalculateGroupPrices;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
Schedule::command('equitab:renewal-reminders')->dailyAt('09:00');
Schedule::job(new RecalculateGroupPrices())->monthlyOn(1, '00:30');
Schedule::command('equitab:update-trust-scores')->daily();
Schedule::command('equitab:generate-sitemap')->daily();
// Filet de sécurité si le webhook Stripe ne joint jamais l'app (local/dev,
// ou une livraison manquée en prod) et que la confirmation front-end a
// échoué silencieusement : réactive les membres réellement payés côté
// Stripe mais restés bloqués "en attente" localement.
Schedule::command('equitab:reconcile-payments')->everyFifteenMinutes();
