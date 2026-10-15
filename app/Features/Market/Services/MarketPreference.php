<?php

namespace App\Features\Market\Services;

use App\Models\User;
use App\Support\BillingCurrencies;
use App\Support\Countries;
use Illuminate\Http\Request;

/** Discovery preference only: never edits residence, contracts or money. */
final class MarketPreference
{
    public function inheritGuestSelection(Request $request, User $user): void
    {
        $country = $request->hasSession() ? $request->session()->get('market_country') : null;
        if ($user->market_country !== null || ! $user->canAccessAccount()
            || ! is_string($country) || ! Countries::contains($country)) {
            return;
        }

        // An explicit visitor preference may seed discovery, never legal residence.
        // The conditional update preserves a choice saved concurrently elsewhere.
        User::whereKey($user->id)->whereNull('market_country')->update(['market_country' => $country]);
        $user->refresh();
    }

    public function forUser(?User $user): ?string
    {
        foreach ([$user?->market_country, $user?->country] as $country) {
            if (Countries::contains($country)) {
                return $country;
            }
        }

        return null;
    }

    public function country(Request $request): ?string
    {
        $country = $this->forUser($request->user()) ?? ($request->hasSession() ? $request->session()->get('market_country') : null);

        return is_string($country) && Countries::contains($country) ? $country : null;
    }

    public function currency(Request $request): ?string
    {
        $country = $this->country($request);

        return $country ? Countries::currency($country) : null;
    }

    public function state(Request $request): array
    {
        $currency = $this->currency($request);

        return [
            'country' => $this->country($request),
            'currency' => $currency,
            'enabled' => $currency === null || in_array($currency, BillingCurrencies::enabled(), true),
            'countries' => Countries::options(),
        ];
    }

    public function select(Request $request, string $country): void
    {
        $country = Countries::normalize($country);
        if ($user = $request->user()) {
            abort_unless($user->canAccessAccount(), 403);
            // Only this preference is writable. In particular, no Stripe country change.
            $user->update(['market_country' => $country]);
        }
        $request->session()->put('market_country', $country);
    }
}
