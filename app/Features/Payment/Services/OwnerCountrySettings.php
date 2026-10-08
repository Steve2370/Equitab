<?php

namespace App\Features\Payment\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OwnerCountrySettings
{
    public function __construct(private readonly OwnerCountries $countries) {}

    public function state(User $user): array
    {
        $locked = $this->locked($user);

        return [
            'country' => $user->country,
            'locked' => $locked,
            'canStart' => $locked || $this->countries->enabled($user->country),
            'countries' => $this->countries->options(),
        ];
    }

    public function select(User $user, string $country): array
    {
        $this->updateProfile($user, ['country' => $country]);

        return $this->state($user->refresh());
    }

    /** Serialize profile country changes with durable Connect account creation. */
    public function updateProfile(User $user, array $attributes): void
    {
        DB::transaction(function () use ($user, $attributes): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($locked->canAccessAccount(), 403);
            $changes = Arr::only($attributes, ['name', 'phone', 'address', 'city', 'province', 'postal_code']);
            if (! array_key_exists('country', $attributes)
                && Arr::hasAny($attributes, ['address', 'city', 'province', 'postal_code'])
                && ($attributes['expected_country'] ?? null) !== $locked->country) {
                throw ValidationException::withMessages([
                    'country' => 'Le pays de votre profil a changé. Rechargez la page avant d’enregistrer cette adresse.',
                ]);
            }
            if (array_key_exists('country', $attributes)) {
                $country = $this->countries->normalize($attributes['country']);
                if ($country !== $locked->country) {
                    if ($this->locked($locked)) {
                        throw ValidationException::withMessages([
                            'country' => 'Le pays est fixé dès le début de l’activation Stripe. Contactez le soutien pour un changement de pays.',
                        ]);
                    }
                    // Never prefill a new country's account with an old address,
                    // including a profile request carrying stale address fields.
                    $changes = array_replace($changes, [
                        'country' => $country, 'address' => null, 'city' => null,
                        'province' => null, 'postal_code' => null,
                    ]);
                }
            }
            $locked->update($changes);
        });
    }

    private function locked(User $user): bool
    {
        return filled($user->stripe_connect_account_id)
            || DB::table('owner_connect_attempts')->where('user_id', $user->id)->exists();
    }
}
