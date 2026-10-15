<?php

namespace App\Features\Payment\Services;

use App\Support\Countries;
use Illuminate\Validation\ValidationException;

/** Country eligibility is separate from the currency of a group. */
final class OwnerCountries
{
    /** @return list<array{code: string, name: string, enabled: bool}> */
    public function options(): array
    {
        $options = [];
        foreach (Countries::options() as $option) {
            $options[] = ['code' => $option['code'], 'name' => $option['name'], 'enabled' => $this->enabled($option['code'])];
        }

        return $options;
    }

    public function normalize(?string $country): string
    {
        return Countries::normalize($country);
    }

    public function enabled(?string $country): bool
    {
        return Countries::contains($country)
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
