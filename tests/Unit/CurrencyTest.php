<?php

namespace Tests\Unit;

use App\Support\Currency;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CurrencyTest extends TestCase
{
    public function test_supported_codes_are_normalized_without_converting_any_amount(): void
    {
        $this->assertSame(['CAD', 'EUR'], Currency::SUPPORTED);
        $this->assertSame('CAD', Currency::normalize(' cad '));
        $this->assertSame('EUR', Currency::normalize('eur'));
    }

    public static function unknownCodes(): array
    {
        return array_map(fn ($code) => [$code], ['', 'USD', 'CAD EUR', '€', '<script>', 'CADE', 'E\u{0423}R']);
    }

    #[DataProvider('unknownCodes')]
    public function test_unknown_codes_are_not_silently_replaced_by_cad(string $code): void
    {
        $this->expectException(InvalidArgumentException::class);
        Currency::normalize($code);
    }
}
