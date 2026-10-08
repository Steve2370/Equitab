<?php

declare(strict_types=1);

namespace App\Support;

final class MoneyFormatter
{
    /**
     * French presentation of native minor units; no conversion or rounding.
     */
    public static function format(int $amount, string $currency): string
    {
        $label = match (Currency::normalize($currency)) {
            'CAD' => '$ CAD',
            'EUR' => '€ EUR',
        };

        // Keep every cent, including at PHP's integer limits, without floats.
        $units = (string) abs(intdiv($amount, 100));
        $units = preg_replace('/\B(?=(\d{3})+(?!\d))/', "\u{202F}", $units);
        $cents = str_pad((string) abs($amount % 100), 2, '0', STR_PAD_LEFT);

        return ($amount < 0 ? '-' : '').$units.','.$cents."\u{00A0}".$label;
    }
}
