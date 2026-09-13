<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            // Partager le modèle User brut exposait, sur CHAQUE page (pas
            // seulement les pages de profil), des champs qui n'ont rien à
            // faire dans le navigateur : identifiants Stripe internes
            // (stripe_connect_account_id, stripe_customer_id,
            // stripe_identity_session_id), téléphone, adresse postale
            // complète, etc. Seuls name/email/identity_status/avatar sont
            // réellement utilisés par le frontend (layouts, barre de
            // navigation) — les pages qui ont besoin de plus (Profile,
            // Preferences) reçoivent déjà leur propre projection dédiée
            // depuis DashboardController.
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'identity_status' => $user->identity_status,
                    'avatar' => $user->avatar,
                ] : null,
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            'isAdmin' => $user && in_array($user->email, \App\Http\Middleware\EnsureIsAdmin::ADMIN_EMAILS),
        ];
    }
}
