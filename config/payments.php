<?php

return [
    // Canada + euro area. Explicit environment overrides remain the rollout/rollback switch.
    // Disabling prevents new engagements, never renewal/refund of existing ones.
    'eur_enabled' => env('EQUITAB_EUR_ENABLED', true),

    // New euro-area Connect accounts only. Existing accounts/attempts remain resumable.
    'eurozone_connect_enabled' => env('EQUITAB_EUROZONE_CONNECT_ENABLED', true),
];
