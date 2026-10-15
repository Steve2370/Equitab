<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/** Supported markets, not a declaration of residence or Stripe eligibility. */
final class Countries
{
    // EU euro-area members, including Bulgaria since 1 January 2026.
    private const NAMES = [
        'CA' => 'Canada', 'DE' => 'Allemagne', 'AT' => 'Autriche',
        'BE' => 'Belgique', 'BG' => 'Bulgarie', 'CY' => 'Chypre',
        'HR' => 'Croatie', 'ES' => 'Espagne', 'EE' => 'Estonie',
        'FI' => 'Finlande', 'FR' => 'France', 'GR' => 'Grèce',
        'IE' => 'Irlande', 'IT' => 'Italie', 'LV' => 'Lettonie',
        'LT' => 'Lituanie', 'LU' => 'Luxembourg', 'MT' => 'Malte',
        'NL' => 'Pays-Bas', 'PT' => 'Portugal', 'SK' => 'Slovaquie',
        'SI' => 'Slovénie',
    ];

    public static function codes(): array
    {
        return array_keys(self::NAMES);
    }

    public static function contains(?string $country): bool
    {
        return isset(self::NAMES[$country ?? '']);
    }

    public static function normalize(?string $country): string
    {
        $code = strtoupper(trim((string) $country));
        if (! self::contains($code)) {
            throw ValidationException::withMessages([
                'country' => 'Choisissez le Canada ou un pays de la zone euro proposé.',
            ]);
        }

        return $code;
    }

    public static function currency(string $country): string
    {
        return self::normalize($country) === 'CA' ? 'CAD' : 'EUR';
    }

    /** @return list<array{code: string, name: string, currency: string}> */
    public static function options(): array
    {
        return array_map(fn ($code) => [
            'code' => $code, 'name' => self::NAMES[$code], 'currency' => self::currency($code),
        ], self::codes());
    }
}
