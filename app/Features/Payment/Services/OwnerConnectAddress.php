<?php

namespace App\Features\Payment\Services;

use App\Models\User;

/** Optional, user-supplied hints; Stripe remains responsible for verification. */
final class OwnerConnectAddress
{
    public function forUser(User $user, string $country): ?array
    {
        if (! filled($user->address) || ! filled($user->city)) {
            return null;
        }
        $region = trim((string) $user->province);
        $postal = trim((string) $user->postal_code);
        if ($country === 'CA') {
            $region = strtoupper($region);
            $postal = strtoupper($postal);
            if (! in_array($region, ['AB', 'BC', 'MB', 'NB', 'NL', 'NS', 'NT', 'NU', 'ON', 'PE', 'QC', 'SK', 'YT'], true)
                || ! preg_match('/^[ABCEGHJ-NPRSTVXY][0-9][ABCEGHJ-NPRSTV-Z] ?[0-9][ABCEGHJ-NPRSTV-Z][0-9]$/', $postal)) {
                return null;
            }
        }

        return array_filter([
            'line1' => trim($user->address), 'city' => trim($user->city),
            'state' => $region, 'postal_code' => $postal, 'country' => $country,
        ], static fn (string $value): bool => $value !== '');
    }
}
