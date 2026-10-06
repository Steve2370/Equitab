<?php

namespace App\Features\Auth\Services;

use App\Mail\WelcomeUser;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Two\User as OAuth2User;

class SocialAuthService
{
    public function __construct(private readonly AccountAccessRevoker $revoker) {}

    public function findOrCreateUser(string $provider, SocialiteUser $identity): User
    {
        $this->validateIdentity($provider, $identity);
        $email = mb_strtolower(trim($identity->getEmail()));

        return DB::transaction(function () use ($provider, $identity, $email): User {
            $user = User::query()->whereHas('oauthProviders', function ($query) use ($provider, $identity) {
                $query->where('provider', $provider)->where('provider_id', $identity->getId());
            })->lockForUpdate()->first();

            $user ??= User::query()->whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first();
            $isNew = $user === null;
            $user ??= User::create([
                'name' => $identity->getName() ?: $identity->getNickname() ?: 'Nouvel utilisateur',
                'email' => $email,
                'password' => null,
                'status' => 'active',
                'avatar' => $identity->getAvatar(),
            ]);

            if (! $user->canAccessAccount()) {
                throw ValidationException::withMessages(['email' => 'Ce compte est suspendu ou désactivé.']);
            }

            if (! $isNew && ! $user->hasVerifiedEmail()) {
                // Email ownership has now been proven. No credential issued to
                // the unverified pre-registrant may survive this recovery.
                $user = $this->revoker->replacePassword($user, null);
                $user->oauthProviders()->delete();
                Password::deleteToken($user);
            }

            $linked = $user->oauthProviders()->where('provider', $provider)->first();
            if ($linked && $linked->provider_id !== (string) $identity->getId()) {
                throw ValidationException::withMessages(['email' => 'Ce compte est déjà lié à une autre identité Google.']);
            }

            $user->oauthProviders()->updateOrCreate(['provider' => $provider], [
                'provider_id' => $identity->getId(),
                'avatar' => $identity->getAvatar(),
            ]);

            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }

            if ($isNew) {
                DB::afterCommit(function () use ($user): void {
                    try {
                        Mail::to($user->email)->send(new WelcomeUser($user));
                    } catch (\Exception) {
                        Log::warning('Welcome email failed (social sign-up).', ['user_id' => $user->id]);
                    }
                    event(new Registered($user));
                });
            }

            return $user;
        });
    }

    private function validateIdentity(string $provider, SocialiteUser $identity): void
    {
        // Socialite's generic contract makes no email-verification guarantee.
        // Only the enabled Google adapter's explicit assertion is accepted.
        $raw = $identity instanceof OAuth2User ? $identity->getRaw() : [];
        $verified = ($raw['email_verified'] ?? $raw['verified_email'] ?? false) === true;

        if ($provider !== 'google' || ! in_array($provider, config('oauth.providers', []), true)
            || ! $verified || ! is_string($identity->getEmail())
            || ! filter_var($identity->getEmail(), FILTER_VALIDATE_EMAIL)
            || ! is_string($identity->getId()) || $identity->getId() === '') {
            throw ValidationException::withMessages([
                'email' => 'Google doit confirmer votre adresse courriel pour permettre la connexion.',
            ]);
        }
    }
}
