<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class BillingCurrencies
{
    /** @return list<string> */
    public static function enabled(): array
    {
        return config('payments.eur_enabled') === true ? Currency::SUPPORTED : ['CAD'];
    }

    public static function assertEnabled(string $currency): void
    {
        if (! in_array(Currency::normalize($currency), self::enabled(), true)) {
            throw ValidationException::withMessages([
                'currency' => 'Les nouveaux paiements en euros ne sont pas encore activés. Votre brouillon reste enregistré.',
            ]);
        }
    }
}
