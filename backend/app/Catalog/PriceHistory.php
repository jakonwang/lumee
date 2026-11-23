<?php

namespace App\Catalog;

use App\Markets\MarketContext;
use App\Models\MarketPrice;
use App\Models\ProductVariant;
use App\Tenancy\CurrentStore;
use Carbon\Carbon;
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
            'store_id' => $v->getAttribute('store_id'), 'product_variant_id' => $v->id, 'market_id' => null,
            'price' => max(0, (int) ($v->getAttributes()['price'] ?? 0)), 'created_at' => now(),
        ]);
    }

    /** A market's fixed price for a variant was set, changed (price) or removed (null: follows the store price again). */
    public static function recordMarket(MarketPrice $p, bool $removed = false): void
    {
        DB::table('variant_price_history')->insert([
            'store_id' => $p->getAttribute('store_id'), 'product_variant_id' => $p->variant_id, 'market_id' => $p->market_id,
            'price' => $removed || $p->price === null ? null : (int) $p->price, 'created_at' => now(),
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
        $markets = app(MarketContext::class);
        $marketId = $markets->market()?->id;
        $rows = DB::table('variant_price_history')->whereIn('product_variant_id', $variantIds)
            ->where(fn ($q) => $q->whereNull('market_id')->when($marketId, fn ($q) => $q->orWhere('market_id', $marketId)))
            ->orderBy('created_at')->orderBy('id')->get(['product_variant_id', 'market_id', 'price', 'created_at'])
            ->groupBy('product_variant_id');

        $out = [];
        foreach ($rows as $id => $events) {
            // The price shoppers of this site saw over time: the market's fixed price while there was one, else the store price converted
            $store = null;
            $fixed = null;
            $timeline = [];
            foreach ($events as $e) {
                if ($e->market_id === null) {
                    $store = (int) $e->price;
                } else {
                    $fixed = $e->price === null ? null : (int) $e->price;
                }
                if ($store === null && $fixed === null) {
                    continue;
                }
                $timeline[] = [$fixed ?? $markets->convertPrice((int) $store), now()->parse($e->created_at)];
            }
            if ($low = self::lowestOf($timeline)) {
                $out[(int) $id] = $low;
            }
        }

        return $out;
    }

    /** @param  list<array{0: int, 1: Carbon}>  $timeline  price from that moment on, oldest first */
    private static function lowestOf(array $timeline): ?int
    {
        if (! $timeline) {
            return null;
        }
        // When did today's price start? (the last change to a different price)
        $current = end($timeline)[0];
        $start = null;
        for ($i = count($timeline) - 1; $i >= 0 && $timeline[$i][0] === $current; $i--) {
            $start = $timeline[$i][1];
        }
        $before = array_values(array_filter($timeline, fn ($t) => $t[1]->lt($start)));
        if (! $before) {
            return null;
        }
        $from = $start->copy()->subDays(self::DAYS);
        // Prices during the window, plus the one already in effect when it opened
        $prices = array_map(fn ($t) => $t[0], array_filter($before, fn ($t) => $t[1]->gte($from)));
        $opening = array_values(array_filter($before, fn ($t) => $t[1]->lt($from)));
        if ($opening) {
            $prices[] = end($opening)[0];
        }

        return $prices ? min($prices) : null;
    }
}
