<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A country (or group of countries) the store sells to with its own currency, languages,
 * URL and prices. See App\Markets\MarketContext for how a storefront request uses it.
 */
class Market extends Model
{
    use BelongsToStore;

    /** none | .99 | .95 | .90 endings, or round up to a whole 1 / 10 / 100 / 1000 units. */
    public const ROUNDING = ['none', '0.99', '0.95', '0.90', '1', '10', '100', '1000'];

    protected $fillable = [
        'name', 'handle', 'countries', 'currency', 'default_locale', 'locales',
        'price_adjustment', 'rounding', 'exchange_rate', 'enabled', 'position', 'settings',
    ];

    protected function casts(): array
    {
        return [
            'countries' => 'array',
            'settings' => 'array',
            'locales' => 'array',
            'price_adjustment' => 'float',
            'exchange_rate' => 'float',
            'enabled' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(MarketPrice::class);
    }

    public function exclusions(): HasMany
    {
        return $this->hasMany(MarketProductExclusion::class);
    }

    /** Languages in URL order: the default first. */
    public function languages(): array
    {
        return array_values(array_unique([$this->default_locale, ...(array) $this->locales]));
    }

    /** URL prefix for a language of this market: "/es-mx". */
    public function prefix(?string $locale = null): string
    {
        return '/'.($locale ?? $this->default_locale).'-'.$this->handle;
    }

    /** Store currency → market currency: the manual rate, else today's rate (null = unknown). */
    public function rate(?Store $store = null): ?float
    {
        $store ??= $this->store;
        $base = $store->baseCurrency();
        if ($this->currency === $base) {
            return 1.0;
        }

        return $this->exchange_rate ?: app(\App\Currency\ExchangeRates::class)->rate($base, $this->currency);
    }

    /** Price ending, e.g. 26.91 → 26.99 ("0.99") or 26,910 → 27,000 ("1000"). Minor units in and out. */
    public static function round(int $cents, string $rounding): int
    {
        if ($cents <= 0) {
            return max(0, $cents);
        }

        return match ($rounding) {
            '0.99', '0.95', '0.90' => (function () use ($cents, $rounding) {
                $end = (int) round((float) $rounding * 100);
                $p = intdiv($cents, 100) * 100 + $end;

                return $p >= $cents ? $p : $p + 100;   // 12.34 → 12.99, 12.99 → 12.99, 13.00 → 13.99
            })(),
            '1', '10', '100', '1000' => (int) ceil($cents / ((int) $rounding * 100)) * (int) $rounding * 100,
            default => $cents,
        };
    }
}
