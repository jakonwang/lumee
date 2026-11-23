<?php

namespace App\Catalog;

use App\Markets\MarketContext;
use App\Models\ProductVariant;
use App\Tenancy\CurrentStore;
use Illuminate\Support\Facades\DB;

/**
 * EU price-reduction rule (Omnibus directive, Art. 6a): a discount shown against a struck-through price must also
 * state the lowest price of the 30 days before the reduction. Variant price changes are recorded here; the product
 * page of an EU site shows that lowest price next to a sale price.
 */
class PriceHistory
{
    public const EU = ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE'];

    public const DAYS = 30;

    /** A variant's price was set or changed. */
    public static function record(ProductVariant $v): void
    {
        DB::table('variant_price_history')->insert([
            'store_id' => $v->getAttribute('store_id'), 'product_variant_id' => $v->id,
            'price' => max(0, (int) ($v->getAttributes()['price'] ?? 0)), 'created_at' => now(),
        ]);
    }

    /** Does the current storefront site sell into the EU? (a market with EU countries, or a store priced in euros) */
    public static function applies(): bool
    {
        $market = app(MarketContext::class)->market();
        if ($market) {
            return (bool) array_intersect(array_map('strtoupper', (array) $market->countries), self::EU);
        }

        return strtoupper((string) app(CurrentStore::class)->get()?->currency) === 'EUR';
    }

    /**
     * Lowest price (minor units, in the site's currency like variant prices) in the 30 days before each variant's current price took effect.
     * Variants without an earlier price are left out (nothing to compare with).
     *
     * @param  int[]  $variantIds
     * @return array<int, int>
     */
    public static function lowestBefore(array $variantIds): array
    {
        if (! $variantIds) {
            return [];
        }
        $rows = DB::table('variant_price_history')->whereIn('product_variant_id', $variantIds)
            ->orderBy('product_variant_id')->orderBy('created_at')->orderBy('id')->get(['product_variant_id', 'price', 'created_at'])
            ->groupBy('product_variant_id');

        $out = [];
        foreach ($rows as $id => $history) {
            $history = $history->values();
            // When did today's price start? (the last change to a different price)
            $current = (int) $history->last()->price;
            $start = null;
            for ($i = $history->count() - 1; $i >= 0; $i--) {
                if ((int) $history[$i]->price !== $current) {
                    break;
                }
                $start = $history[$i]->created_at;
            }
            $before = $history->filter(fn ($h) => $h->created_at < $start);
            if ($before->isEmpty()) {
                continue;
            }
            $from = now()->parse($start)->subDays(self::DAYS);
            // Prices during the window, plus the one already in effect when it opened
            $window = $before->filter(fn ($h) => now()->parse($h->created_at)->gte($from));
            $opening = $before->filter(fn ($h) => now()->parse($h->created_at)->lt($from))->last();
            $prices = $window->pluck('price')->push($opening?->price)->filter(fn ($p) => $p !== null)->map(fn ($p) => (int) $p);
            if ($prices->isNotEmpty()) {
                // In a market, shown like the variant's own price there (adjustment + price ending)
                $out[(int) $id] = app(MarketContext::class)->convertPrice($prices->min());
            }
        }

        return $out;
    }
}
