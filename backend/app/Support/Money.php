<?php

namespace App\Support;

/**
 * Money is stored as integer minor units (e.g. cents). Never use floats for amounts.
 */
final class Money
{
    public static function toMinor(float|int|string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    public static function format(?int $minor): string
    {
        return number_format(($minor ?? 0) / 100, 2, '.', '');
    }

    public static function display(?int $minor, string $currency): string
    {
        $symbols = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'CNY' => '¥', 'JPY' => '¥', 'AUD' => 'A$', 'CAD' => 'C$'];

        return ($symbols[$currency] ?? $currency.' ').number_format(($minor ?? 0) / 100, 2);
    }
}
