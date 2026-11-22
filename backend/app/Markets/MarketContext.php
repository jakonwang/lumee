<?php

namespace App\Markets;

use App\Models\Market;
use App\Models\MarketPrice;
use App\Models\MarketProductExclusion;
use App\Models\Store;

/**
 * The market a storefront request is in (request-scoped). Null = the primary market: the store's
 * own currency, prices and languages, exactly as before markets existed.
 *
 * Inside a market everything the shopper sees and pays is in the market currency:
 *  - Store::currency reads as the market currency (the saved value is Store::baseCurrency()),
 *    so carts, checkout, orders and payment gateways use it without knowing about markets;
 *  - variant prices are the market's fixed prices, else the store price converted with the
 *    market's rate, adjustment and price ending (ProductVariant::getAttribute);
 *  - amounts the merchant entered in the store currency (shipping rates, fixed discounts,
 *    thresholds, wholesale prices) are converted with money().
 */
class MarketContext
{
    private ?Market $market = null;

    private ?float $rate = null;

    /** @var array<int, array{0: ?int, 1: ?int}>|null variant id => [price, compare_at_price] */
    private ?array $fixed = null;

    /** @var array<int, true>|null */
    private ?array $excluded = null;

    public function set(?Market $market, ?float $rate = null): void
    {
        $this->market = $market;
        $this->rate = $market ? ($rate ?? $market->rate()) : null;
        $this->fixed = null;
        $this->excluded = null;
    }

    /** Run $fn inside a market (e.g. a recovery email for a cart started there), then restore. */
    public function within(?Market $market, callable $fn): mixed
    {
        $saved = [$this->market, $this->rate, $this->fixed, $this->excluded];
        $this->set($market);
        try {
            return $fn();
        } finally {
            [$this->market, $this->rate, $this->fixed, $this->excluded] = $saved;
        }
    }

    public function market(): ?Market
    {
        return $this->market;
    }

    public function active(): bool
    {
        return $this->market !== null;
    }

    /**
     * Is this store's money being shown in a market currency right now? A row loaded without its
     * store_id (null) counts as the current store's: storefront queries are scoped to it anyway.
     */
    public function activeFor(?int $storeId): bool
    {
        return $this->market !== null && ($storeId === null || $this->market->store_id === $storeId);
    }

    public function currency(): ?string
    {
        return $this->market?->currency;
    }

    /** 1 store currency = rate() market currency (1 outside a market). */
    public function rate(): float
    {
        return $this->market ? (float) $this->rate : 1.0;
    }

    /** An amount the merchant entered in the store currency, in the market currency (no price ending). */
    public function money(?int $minor): ?int
    {
        if ($minor === null || ! $this->market) {
            return $minor;
        }

        return (int) round($minor * $this->rate);
    }

    /** Store price → market price with the market's adjustment and price ending. */
    public function convertPrice(int $minor): int
    {
        if (! $this->market || $minor <= 0) {
            return $minor;
        }
        $m = $this->market;

        return Market::round((int) round($minor * $this->rate * (1 + $m->price_adjustment / 100)), $m->rounding);
    }

    /**
     * Price and compare-at price of a variant in this market: the fixed price if set,
     * else converted from the store price. A compare-at that isn't above the price is dropped.
     *
     * @return array{0: int, 1: ?int}
     */
    public function variantPrices(int $variantId, int $price, ?int $compareAt): array
    {
        if (! $this->market) {
            return [$price, $compareAt];
        }
        $this->fixed ??= MarketPrice::where('market_id', $this->market->id)->get(['variant_id', 'price', 'compare_at_price'])
            ->mapWithKeys(fn ($p) => [$p->variant_id => [$p->price, $p->compare_at_price]])->all();
        [$fixedPrice, $fixedCompare] = $this->fixed[$variantId] ?? [null, null];

        $p = $fixedPrice ?? $this->convertPrice($price);
        $c = $fixedCompare ?? ($compareAt ? $this->convertPrice($compareAt) : null);

        return [$p, $c !== null && $c > $p ? $c : null];
    }

    /** Products not sold in this market (ids as keys). */
    public function excluded(): array
    {
        if (! $this->market) {
            return [];
        }

        return $this->excluded ??= MarketProductExclusion::where('market_id', $this->market->id)->pluck('product_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    public function sells(int $productId): bool
    {
        return ! isset($this->excluded()[$productId]);
    }

    /**
     * Markets a shopper can visit: enabled and with a known exchange rate.
     *
     * @return \Illuminate\Support\Collection<int, Market>
     */
    public static function live(Store $store): \Illuminate\Support\Collection
    {
        return Market::withoutGlobalScope('store')->where('store_id', $store->id)->where('enabled', true)
            ->orderBy('position')->orderBy('id')->get()
            ->each(fn (Market $m) => $m->setRelation('store', $store))
            ->filter(fn (Market $m) => $m->rate($store) !== null)
            ->values();
    }
}
