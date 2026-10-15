<?php

namespace App\Features\Auth\Services;

use App\Mail\WelcomeUser;
use App\Models\OauthProvider;
use App\Models\User;
use App\Support\Countries;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
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

    public function findOrCreateUser(string $provider, SocialiteUser $identity, ?string $country = null): User
    {
        $this->validateIdentity($provider, $identity);
        $country = $country === null ? null : Countries::normalize($country);
        $email = mb_strtolower(trim($identity->getEmail()));

        // A missing identity/email row cannot be locked on PostgreSQL. If two
        // first logins race, roll back and resolve the committed winner afresh.
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->resolveAccount($provider, $identity, $email, $country);
            } catch (UniqueConstraintViolationException $e) {
                if (! $this->isIdentityConflict($e)) {
                    throw $e;
                }
                if ($attempt >= 3) {
                    throw ValidationException::withMessages([
                        'email' => 'Une connexion est déjà en cours. Veuillez réessayer.',
                    ]);
                }
            }
        }
    }

    private function resolveAccount(string $provider, SocialiteUser $identity, string $email, ?string $country): User
    {
        return DB::transaction(function () use ($provider, $identity, $email, $country): User {
            // Look up the globally unique identity independently of User's
            // SoftDeletes scope. Lock it before deciding whether it can move.
            $identityLink = OauthProvider::where('provider', $provider)
                ->where('provider_id', $identity->getId())->lockForUpdate()->first();
            $previousUser = $identityLink
                ? User::withTrashed()->lockForUpdate()->findOrFail($identityLink->user_id)
                : null;

            if ($previousUser?->trashed() && $previousUser->status !== 'active') {
                // Deleting a restricted account must not erase its restriction.
                throw ValidationException::withMessages(['email' => 'Ce compte est suspendu ou désactivé.']);
            }

            $user = $previousUser && ! $previousUser->trashed() ? $previousUser : null;

            $user ??= User::query()->whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first();
            $isNew = $user === null;
            $user ??= User::create([
                'name' => $identity->getName() ?: $identity->getNickname() ?: 'Nouvel utilisateur',
                'email' => $email,
                'password' => null,
                'status' => 'active',
                'avatar' => $identity->getAvatar(),
                'country' => $country,
                'market_country' => $country,
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

            if ($previousUser?->trashed()) {
                // Only the proven login identity moves. Never restore/copy the
                // old user, roles, tokens, Stripe accounts or financial history.
                $identityLink->update(['user_id' => $user->id, 'avatar' => $identity->getAvatar()]);
                DB::afterCommit(fn () => Log::info('Social identity reassigned after account deletion.', [
                    'provider' => $provider, 'previous_user_id' => $previousUser->id, 'user_id' => $user->id,
                ]));
            } else {
                $user->oauthProviders()->updateOrCreate(['provider' => $provider], [
                    'provider_id' => $identity->getId(),
                    'avatar' => $identity->getAvatar(),
                ]);
            }

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
        }, attempts: 3);
    }

    private function isIdentityConflict(UniqueConstraintViolationException $exception): bool
    {
        // Only retry the identity invariants, not unrelated database failures.
        foreach ([
            'users_email_unique', 'oauth_providers_provider_provider_id_unique', 'oauth_providers_user_id_provider_unique',
            'UNIQUE constraint failed: users.email',
            'UNIQUE constraint failed: oauth_providers.provider, oauth_providers.provider_id',
            'UNIQUE constraint failed: oauth_providers.user_id, oauth_providers.provider',
        ] as $constraint) {
            if (str_contains($exception->getPrevious()?->getMessage() ?? '', $constraint)) {
                return true;
            }
        }

        return false;
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
