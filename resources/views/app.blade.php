<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Equitab - Plateforme de partage d'abonnements numériques. Partagez Netflix, Spotify, Disney+ et économisez jusqu'à 75%.">
        <meta name="robots" content="index, follow">
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="Equitab">
        <meta property="og:title" content="Equitab - Partagez vos abonnements au Canada">
        <meta property="og:description" content="Partagez Netflix, Spotify, Disney+ et économisez jusqu'à 75%. Paiements sécurisés par Stripe.">
        <meta property="og:locale" content="fr_CA">
        <meta name="twitter:card" content="summary_large_image">
        <title inertia>{{ config('app.name', 'Equitab') }}</title>
        <script src="https://js.stripe.com/v3/" defer></script>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
        @routes
        @vite(['resources/js/app.ts', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
