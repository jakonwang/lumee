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
        $minor ??= 0;
        // Rupiah prices are whole numbers (Rp89,000): no ".00" unless there really are cents
        $decimals = $currency === 'IDR' && $minor % 100 === 0 ? 0 : 2;

        // Inside a market: the way shoppers there write prices (R$ 200,90 · 27,95 € · $1,601.98)
        if (($locale = self::locale()) && class_exists(\NumberFormatter::class)) {
            $f = new \NumberFormatter(str_replace('-', '_', $locale), \NumberFormatter::CURRENCY);
            $f->setAttribute(\NumberFormatter::FRACTION_DIGITS, $decimals);
            $out = $f->formatCurrency($minor / 100, $currency);
            if ($out !== false) {
                return $out;
            }
        }

        $symbols = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'CNY' => '¥', 'JPY' => '¥', 'AUD' => 'A$', 'CAD' => 'C$',
            'NZD' => 'NZ$', 'SGD' => 'S$', 'HKD' => 'HK$', 'MXN' => 'MX$', 'BRL' => 'R$', 'IDR' => 'Rp', 'PHP' => '₱', 'THB' => '฿', 'MYR' => 'RM'];

        return ($symbols[$currency] ?? $currency.' ').number_format($minor / 100, $decimals);
    }

    /**
     * Number/currency locale for shopper-facing amounts: the shopper's language in the market's
     * country ("pt-BR", "es-MX", "de-DE") inside a market; null elsewhere (the classic "$1,234.56").
     * The storefront JS formats with the same locale (Intl.NumberFormat).
     */
    public static function locale(): ?string
    {
        $market = app(\App\Markets\MarketContext::class)->market();
        $country = $market ? strtoupper((string) (((array) $market->countries)[0] ?? '')) : '';

        if ($country === '') {
            return null;
        }
        $tag = str_replace('_', '-', app()->getLocale()).'-'.$country;

        // Arabic: Western digits (1,299.00), as most stores in the Gulf show prices
        return \App\I18n\Localization::dir() === 'rtl' ? $tag.'-u-nu-latn' : $tag;
    }
}
