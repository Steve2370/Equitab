<?php

namespace App\Support;

use InvalidArgumentException;

final class Currency
{
    public const SUPPORTED = ['CAD', 'EUR'];

    public static function normalize(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        if (! in_array($currency, self::SUPPORTED, true)) {
            throw new InvalidArgumentException('Devise non prise en charge.');
        }

        return $currency;
    }
}
