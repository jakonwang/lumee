<?php

namespace App\Currency;

use App\Support\Money;
use App\Tenancy\CurrentStore;

/**
 * The currency prices are SHOWN in on the storefront for this request. Display only:
 * carts, orders and payments always use the store currency (the checkout says so).
 */
class Presentment
{
    public const COOKIE = 'lumee_currency';

    /** Country → local currency, for auto-detection (only used if the store offers it). */
    private const COUNTRY_CURRENCY = [
        'US' => 'USD', 'GB' => 'GBP', 'CA' => 'CAD', 'AU' => 'AUD', 'NZ' => 'NZD', 'SG' => 'SGD', 'HK' => 'HKD',
        'CN' => 'CNY', 'CH' => 'CHF', 'SE' => 'SEK', 'DK' => 'DKK', 'NO' => 'NOK', 'MX' => 'MXN', 'BR' => 'BRL', 'AE' => 'AED',
        'DE' => 'EUR', 'FR' => 'EUR', 'IT' => 'EUR', 'ES' => 'EUR', 'NL' => 'EUR', 'BE' => 'EUR', 'AT' => 'EUR', 'IE' => 'EUR',
        'PT' => 'EUR', 'FI' => 'EUR', 'GR' => 'EUR', 'LU' => 'EUR', 'SK' => 'EUR', 'SI' => 'EUR', 'EE' => 'EUR', 'LV' => 'EUR',
        'LT' => 'EUR', 'CY' => 'EUR', 'MT' => 'EUR', 'HR' => 'EUR',
    ];

    private ?array $resolved = null;

    public function storeCurrency(): string
    {
        return app(CurrentStore::class)->getOrFail()->currency;
    }

    /** @return array{currency: string, rate: float, rounding: string} */
    public function get(): array
    {
        if ($this->resolved) {
            return $this->resolved;
        }
        $store = app(CurrentStore::class)->getOrFail();
        // Inside a market prices are already in the market currency, which is also what checkout charges
        if (app(\App\Markets\MarketContext::class)->activeFor((int) $store->id)) {
            return $this->resolved = ['currency' => $store->currency, 'rate' => 1.0, 'rounding' => 'none'];
        }
        $settings = CurrencySettings::for($store);
        $offered = $settings->offered();

        $pick = request()->cookie(self::COOKIE);
        if (! in_array($pick, $offered, true)) {
            $pick = null;
            // First visit: shopper's local currency (country from the CDN header, e.g. Cloudflare), if offered
            if ($settings->all()['auto_detect']) {
                $country = strtoupper((string) request()->header('CF-IPCountry'));
                $local = self::COUNTRY_CURRENCY[$country] ?? null;
                $pick = in_array($local, $offered, true) ? $local : null;
            }
        }
        $currency = $pick ?? $store->currency;

        return $this->resolved = [
            'currency' => $currency,
            'rate' => $settings->rate($currency) ?? 1.0,
            'rounding' => $currency === $store->currency ? 'none' : $settings->all()['rounding'],
        ];
    }

    public function currency(): string
    {
        return $this->get()['currency'];
    }

    public function isConverted(): bool
    {
        return $this->currency() !== $this->storeCurrency();
    }

    /** Store-currency minor units → presentment minor units (rounded up per the store's rule). */
    public function convert(int $minor): int
    {
        ['rate' => $rate, 'rounding' => $rounding] = $this->get();

        return self::applyRounding((int) round($minor * $rate), $rounding);
    }

    public static function applyRounding(int $cents, string $rounding): int
    {
        if ($cents <= 0) {
            return max(0, $cents);
        }

        return match ($rounding) {
            '1.00' => (int) ceil($cents / 100) * 100,
            '0.99' => (intdiv($cents, 100) + 1) * 100 - 1,   // 12.34 → 12.99, 12.99 → 12.99, 13.00 → 13.99
            default => $cents,
        };
    }

    public function display(?int $minor): string
    {
        return Money::display($this->convert((int) $minor), $this->currency());
    }

    /** For the storefront JS (variant picker): same math in the browser. */
    public function toJs(): array
    {
        return $this->get();
    }
}
