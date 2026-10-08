<?php

namespace App\Features\Payment\Services;

use Illuminate\Validation\ValidationException;

/** Country eligibility is separate from the currency of a group. */
final class OwnerCountries
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

    /** @return list<array{code: string, name: string, enabled: bool}> */
    public function options(): array
    {
        $options = [];
        foreach (self::NAMES as $code => $name) {
            $options[] = ['code' => $code, 'name' => $name, 'enabled' => $this->enabled($code)];
        }

        return $options;
    }

    public function normalize(?string $country): string
    {
        $code = strtoupper(trim((string) $country));
        if (! isset(self::NAMES[$code])) {
            throw ValidationException::withMessages([
                'country' => 'Choisissez votre pays : le Canada ou un pays de la zone euro proposé.',
            ]);
        }

        return $code;
    }

    public function enabled(?string $country): bool
    {
        return isset(self::NAMES[$country ?? ''])
            && ($country === 'CA' || config('payments.eurozone_connect_enabled') === true);
    }

    public function assertCanStart(?string $country): string
    {
        $code = $this->normalize($country);
        if (! $this->enabled($code)) {
            throw ValidationException::withMessages([
                'country' => 'Les versements pour la zone euro ne sont pas encore ouverts. Vous pouvez préparer et conserver votre brouillon.',
            ]);
        }

        return $code;
    }
}
