<?php

namespace App\Features\Auth\Controllers;

use App\Features\Auth\Services\AccountSession;
use App\Features\Auth\Services\SocialAuthService;
use App\Features\Market\Services\MarketPreference;
use App\Http\Controllers\Controller;
use App\Support\Countries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
        // Explicit selection, never inferred from Google, IP address or locale.
        request()->session()->forget('registration_country');
        if (request()->filled('country')) {
            $data = request()->validate(['country' => ['string', Rule::in(Countries::codes())]]);
            request()->session()->put('registration_country', $data['country']);
        }

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
                'La connexion via '.Str::ucfirst($provider).' a échoué. Veuillez réessayer.',
            );
        }

        try {
            $user = $this->socialAuthService->findOrCreateUser($provider, $socialiteUser, request()->session()->pull('registration_country'));
        } catch (ValidationException $e) {
            return redirect()->route('login')->withErrors($e->errors());
        }

        Auth::login($user, remember: true);

        request()->session()->regenerate();
        app(AccountSession::class)->remember(request()->session(), $user);
        app(MarketPreference::class)->inheritGuestSelection(request(), $user);

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
