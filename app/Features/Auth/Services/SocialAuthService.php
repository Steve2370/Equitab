<?php

namespace App\Features\Auth\Services;

use App\Mail\WelcomeUser;
use App\Models\User;
use App\Services\Wallet\WalletService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class SocialAuthService
{
    public function __construct(
        private readonly WalletService $walletService,
    ) {}

    /**
     * Find an existing user matching this provider account, or create a new
     * one. Works identically for any provider (google, github, ...) — the
     * only provider-specific input is the $provider string and whatever
     * Socialite normalized onto the SocialiteUser contract.
     *
     * - If a user is already linked to this provider account, return it.
     * - If a user exists with the same email (password account, or another
     *   linked provider), link this provider to it. The provider has
     *   already verified the email address, so this link is safe and also
     *   marks the email verified if it wasn't already.
     * - Otherwise, create a brand new user, mirroring what
     *   RegisteredUserController does on classic sign-up (wallet, welcome
     *   email, Registered event) so every sign-up path leaves the account
     *   in the same state.
     *
     * @throws ValidationException if the matched account is suspended/banned.
     */
    public function findOrCreateUser(string $provider, SocialiteUser $socialiteUser): User
    {
        $user = User::whereHas('oauthProviders', function ($query) use ($provider, $socialiteUser) {
            $query->where('provider', $provider)
                ->where('provider_id', $socialiteUser->getId());
        })->first();

        if (! $user) {
            $user = User::where('email', $socialiteUser->getEmail())->first();
        }

        if (! $user) {
            $user = $this->createNewUser($socialiteUser);
        }

        $this->ensureUserIsActive($user);

        $user->oauthProviders()->updateOrCreate(
            ['provider' => $provider],
            [
                'provider_id' => $socialiteUser->getId(),
                'avatar' => $socialiteUser->getAvatar(),
            ],
        );

        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $user;
    }

    private function createNewUser(SocialiteUser $socialiteUser): User
    {
        $user = User::create([
            'name' => $socialiteUser->getName() ?: $socialiteUser->getNickname() ?: 'Nouvel utilisateur',
            'email' => $socialiteUser->getEmail(),
            'password' => null,
            'status' => 'active',
            'email_verified_at' => now(),
            'avatar' => $socialiteUser->getAvatar(),
        ]);

        $this->walletService->createForUser($user);

        try {
            Mail::to($user->email)->send(new WelcomeUser($user));
        } catch (\Exception $e) {
            Log::error('Welcome email failed (social sign-up): ' . $e->getMessage());
        }

        event(new Registered($user));

        return $user;
    }

    /**
     * Block login for suspended/banned accounts.
     *
     * Note: the standard email/password login (LoginRequest) does not
     * currently perform this check either — this should be aligned during
     * the auth refactor so every login path enforces the same account
     * status rules.
     */
    private function ensureUserIsActive(User $user): void
    {
        if ($user->status === 'active') {
            return;
        }

        throw ValidationException::withMessages([
            'email' => 'Ce compte a été suspendu. Contactez le support pour plus d\'informations.',
        ]);
    }
}
