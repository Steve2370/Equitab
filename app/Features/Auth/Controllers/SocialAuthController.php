<?php

namespace App\Features\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Features\Auth\Services\SocialAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    public function __construct(
        private readonly SocialAuthService $socialAuthService,
    ) {}

    /**
     * Redirect the user to the provider's OAuth consent screen.
     *
     * $provider is constrained at the route level (whereIn on
     * config('oauth.providers')), so by the time it reaches here it is
     * guaranteed to be a known, configured Socialite driver.
     */
    public function redirect(string $provider): RedirectResponse
    {
        return Socialite::driver($provider)->redirect();
    }

    /**
     * Handle the callback from the provider after the user grants consent.
     */
    public function callback(string $provider): RedirectResponse
    {
        try {
            $socialiteUser = Socialite::driver($provider)->user();
        } catch (\Exception $e) {
            return redirect()->route('login')->with(
                'status',
                'La connexion via ' . Str::ucfirst($provider) . ' a échoué. Veuillez réessayer.',
            );
        }

        try {
            $user = $this->socialAuthService->findOrCreateUser($provider, $socialiteUser);
        } catch (ValidationException $e) {
            return redirect()->route('login')->withErrors($e->errors());
        }

        Auth::login($user, remember: true);

        request()->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
