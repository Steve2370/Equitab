<?php

return [
    // Enable only after the EUR sandbox journey and account eligibility are checked.
    // Disabling this prevents new engagements, never renewal/refund of existing ones.
    'eur_enabled' => env('EQUITAB_EUR_ENABLED', false),

    // New euro-area Connect accounts only. Existing accounts/attempts remain resumable.
    'eurozone_connect_enabled' => env('EQUITAB_EUROZONE_CONNECT_ENABLED', false),
];
