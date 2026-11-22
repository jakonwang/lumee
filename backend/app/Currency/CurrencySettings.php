<?php

namespace App\Currency;

use App\Models\Store;

/**
 * Per-store multi-currency display settings (stores.settings['currencies']):
 *   enabled     — show the currency picker on the storefront
 *   currencies  — extra currencies shoppers may pick (the store currency is always available)
 *   rounding    — none | 0.99 | 1.00 (how converted prices are rounded up)
 *   rates       — optional manual rates {EUR: 0.92} that override the daily rate
 *   auto_detect — preselect the shopper's local currency from their country, when known
 */
final class CurrencySettings
{
    public const ROUNDING = ['none', '0.99', '1.00'];

    public function __construct(private Store $store) {}

    public static function for(Store $store): self
    {
        return new self($store);
    }

    public function all(): array
    {
        $s = $this->store->settings['currencies'] ?? [];

        return [
            'enabled' => (bool) ($s['enabled'] ?? false),
            'currencies' => array_values(array_filter((array) ($s['currencies'] ?? []),
                fn ($c) => in_array($c, config('lumee.currencies'), true) && $c !== $this->store->baseCurrency())),
            'rounding' => in_array($s['rounding'] ?? null, self::ROUNDING, true) ? $s['rounding'] : '0.99',
            'rates' => array_filter((array) ($s['rates'] ?? []), fn ($v) => is_numeric($v) && $v > 0),
            'auto_detect' => (bool) ($s['auto_detect'] ?? true),
        ];
    }

    public function save(array $data): void
    {
        $settings = $this->store->settings ?? [];
        $settings['currencies'] = [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'currencies' => array_values(array_unique(array_diff($data['currencies'] ?? [], [$this->store->baseCurrency()]))),
            'rounding' => $data['rounding'] ?? '0.99',
            'rates' => collect($data['rates'] ?? [])->filter(fn ($v) => is_numeric($v) && $v > 0)->map(fn ($v) => (float) $v)->all(),
            'auto_detect' => (bool) ($data['auto_detect'] ?? true),
        ];
        $this->store->settings = $settings;
        $this->store->save();
    }

    /** Store currency → $to rate: manual override first, else today's rate (null = not available). */
    public function rate(string $to): ?float
    {
        $base = $this->store->baseCurrency();
        if ($to === $base) {
            return 1.0;
        }

        return (float) ($this->all()['rates'][$to] ?? 0) ?: app(ExchangeRates::class)->rate($base, $to);
    }

    /** Currencies shoppers can actually pick right now (enabled, with a known rate). */
    public function offered(): array
    {
        // Inside a market prices are already in its currency: no picker
        if (app(\App\Markets\MarketContext::class)->activeFor((int) $this->store->id)) {
            return [$this->store->currency];
        }
        $all = $this->all();
        $base = $this->store->baseCurrency();
        if (! $all['enabled']) {
            return [$base];
        }

        return [$base, ...array_values(array_filter($all['currencies'], fn ($c) => $this->rate($c) !== null))];
    }
}
