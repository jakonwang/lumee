<?php

namespace App\Services;

use App\Models\Store;
use App\Models\TaxRate;

/**
 * Tax for an order. Two store-wide modes (stores.settings['taxes']):
 *   included = true  → prices already contain tax (EU VAT): tax is the part of the price that is tax,
 *                      the total doesn't change.
 *   included = false → tax is added on top at checkout (US sales tax).
 * Rate lookup: country + region (state/province code) first, then the whole country.
 * Discounts are spread proportionally over the items, so tax applies to what the buyer pays.
 */
class TaxService
{
    /** stripe_tax: US orders use Stripe Tax (address-level rates) instead of the state rates below. */
    public const DEFAULTS = ['included' => false, 'shipping' => false, 'stripe_tax' => false];

    /** Common presets (standard rates, percent). Merchants should confirm with their tax adviser. */
    public const PRESETS = [
        'eu_vat' => ['name' => 'VAT', 'rates' => [
            'AT' => 20, 'BE' => 21, 'BG' => 20, 'HR' => 25, 'CY' => 19, 'CZ' => 21, 'DK' => 25, 'EE' => 22, 'FI' => 25.5,
            'FR' => 20, 'DE' => 19, 'GR' => 24, 'HU' => 27, 'IE' => 23, 'IT' => 22, 'LV' => 21, 'LT' => 21, 'LU' => 17,
            'MT' => 18, 'NL' => 21, 'PL' => 23, 'PT' => 23, 'RO' => 19, 'SK' => 23, 'SI' => 22, 'ES' => 21, 'SE' => 25,
        ]],
        'uk_vat' => ['name' => 'VAT', 'rates' => ['GB' => 20]],
        'au_gst' => ['name' => 'GST', 'rates' => ['AU' => 10]],
        'nz_gst' => ['name' => 'GST', 'rates' => ['NZ' => 15]],
        'ca_gst' => ['name' => 'GST', 'rates' => ['CA' => 5]],
        'ch_vat' => ['name' => 'VAT', 'rates' => ['CH' => 8.1]],
        'no_vat' => ['name' => 'VAT', 'rates' => ['NO' => 25]],
        'jp_ct' => ['name' => 'Consumption tax', 'rates' => ['JP' => 10]],
        'sg_gst' => ['name' => 'GST', 'rates' => ['SG' => 9]],
    ];

    public function settings(Store $store): array
    {
        return array_merge(self::DEFAULTS, array_intersect_key($store->settings['taxes'] ?? [], self::DEFAULTS));
    }

    public function rateFor(?string $country, ?string $region): ?TaxRate
    {
        if (! $country) {
            return null;
        }
        $rates = TaxRate::where('country', strtoupper($country))->get();   // store-scoped
        $region = strtoupper(trim((string) $region));

        return ($region !== '' ? $rates->first(fn ($r) => strtoupper((string) $r->region) === $region) : null)
            ?? $rates->first(fn ($r) => $r->region === null || $r->region === '');
    }

    /**
     * @param  int  $taxableItems  item amount after discount that is taxable (minor units)
     * @return array{amount:int, included:bool, lines:array, rate:float|null, name:string|null}
     */
    public function calculate(Store $store, int $taxableItems, int $shipping, ?string $country, ?string $region, array $address = []): array
    {
        $s = $this->settings($store);
        if ($s['stripe_tax'] && ! $s['included'] && strtoupper((string) $country) === 'US') {
            $st = app(StripeTax::class)->calculate($store, max(0, $taxableItems), $shipping, [...$address, 'province' => $region]);
            if ($st !== null) {
                return [
                    'amount' => $st['amount'], 'included' => false, 'rate' => $st['rate'], 'name' => 'Sales tax',
                    'lines' => $st['amount'] > 0 ? [['name' => 'Sales tax', 'rate' => $st['rate'], 'amount' => $st['amount']]] : [],
                ];
            }
        }
        $rate = $this->rateFor($country, $region);
        $base = max(0, $taxableItems) + ($s['shipping'] ? $shipping : 0);
        if (! $rate || $rate->rate <= 0 || $base <= 0) {
            return ['amount' => 0, 'included' => (bool) $s['included'], 'lines' => [], 'rate' => $rate?->rate, 'name' => $rate?->name];
        }

        $r = $rate->rate / 100;
        $amount = $s['included']
            ? (int) round($base - $base / (1 + $r))   // the tax inside a tax-inclusive price
            : (int) round($base * $r);

        return [
            'amount' => $amount,
            'included' => (bool) $s['included'],
            'lines' => [['name' => $rate->name, 'rate' => $rate->rate, 'amount' => $amount]],
            'rate' => $rate->rate,
            'name' => $rate->name,
        ];
    }

    /** Taxable share of the discounted item total (discount spread proportionally over all items). */
    public static function taxableAfterDiscount(int $subtotal, int $taxableSubtotal, int $discount): int
    {
        if ($subtotal <= 0) {
            return 0;
        }

        return (int) round($taxableSubtotal * max(0, $subtotal - $discount) / $subtotal);
    }
}
