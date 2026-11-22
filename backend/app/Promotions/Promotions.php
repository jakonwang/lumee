<?php

namespace App\Promotions;

use App\Models\Discount;
use App\Models\Promotion;
use App\Models\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Automatic discounts for one store, request-scoped.
 *
 * Config per type (promotions.config):
 *   sale    {value, applies_to: all|products|collections, product_ids[], collection_ids[], countdown}
 *           → lowers the variant price itself (storefront only, see ProductVariant::getAttribute);
 *             the original price becomes the crossed-out "compare at" price
 *   tiered  {tiers: [{min (minor units), value}]}   spend ≥ min → value off the order (best tier wins)
 *   bundle  {value, product_ids[2..10]}            one of each in the bag = a set; value off each set
 *
 * Order-level discounts (tiered + bundles) combine with each other; a discount code is added on top
 * only when every promotion that applies allows it — otherwise the shopper gets whichever is bigger.
 */
class Promotions
{
    private ?Store $store = null;

    /** @var Collection<int, Promotion>|null */
    private ?Collection $active = null;

    /** sale promotion id => [product_id => true] for collection-based sales */
    private array $collectionProducts = [];

    private array $saleCache = [];

    /** Turn sale pricing on for this request (storefront requests, recovery emails). */
    public function enableFor(Store $store): void
    {
        $this->store = $store;
        $this->active = null;
        $this->saleCache = [];
    }

    public function enabled(): bool
    {
        return $this->store !== null;
    }

    /** @return Collection<int, Promotion> */
    public function active(): Collection
    {
        if (! $this->store) {
            return collect();
        }
        if ($this->active === null) {
            $this->active = Promotion::withoutGlobalScope('store')->where('store_id', $this->store->id)->active()->orderBy('id')->get();
            $collectionIds = $this->active->where('type', 'sale')->filter(fn ($p) => ($p->config['applies_to'] ?? 'all') === 'collections')
                ->mapWithKeys(fn ($p) => [$p->id => array_map('intval', $p->config['collection_ids'] ?? [])]);
            $all = $collectionIds->flatten()->unique()->all();
            $pivot = $all ? DB::table('collection_product')->whereIn('collection_id', $all)->get(['collection_id', 'product_id']) : collect();
            foreach ($collectionIds as $pid => $ids) {
                $this->collectionProducts[$pid] = $pivot->whereIn('collection_id', $ids)->pluck('product_id')->flip()->map(fn () => true)->all();
            }
        }

        return $this->active;
    }

    /** The sale that gives this product its lowest price, if any. */
    public function saleFor(int $productId, int $price): ?Promotion
    {
        $best = null;
        $bestPrice = $price;
        foreach ($this->active()->where('type', 'sale') as $p) {
            if (! $this->saleCovers($p, $productId)) {
                continue;
            }
            $sale = $price - $p->reduce($price);
            if ($sale < $bestPrice) {
                [$best, $bestPrice] = [$p, $sale];
            }
        }

        return $best;
    }

    /** Variant price after the best sale (minor units). */
    public function salePrice(int $productId, int $price): int
    {
        if (! $this->store || $price <= 0) {
            return $price;
        }
        $key = $productId.'|'.$price;

        return $this->saleCache[$key] ??= ($p = $this->saleFor($productId, $price)) ? $price - $p->reduce($price) : $price;
    }

    /** Spend-more tiers; thresholds and fixed values are in the store currency, so converted inside a market. */
    private function tiers(Promotion $p): array
    {
        $markets = app(\App\Markets\MarketContext::class);

        return array_map(fn ($t) => [
            ...$t,
            'min' => $markets->money((int) ($t['min'] ?? 0)),
            'value' => $p->value_type === 'percentage' ? (float) ($t['value'] ?? 0) : $markets->money((int) round((float) ($t['value'] ?? 0) * 100)) / 100,
        ], (array) ($p->config['tiers'] ?? []));
    }

    private function saleCovers(Promotion $p, int $productId): bool
    {
        return match ($p->config['applies_to'] ?? 'all') {
            'products' => in_array($productId, array_map('intval', $p->config['product_ids'] ?? []), true),
            'collections' => isset($this->collectionProducts[$p->id][$productId]),
            default => true,
        };
    }

    /**
     * Order-level discounts for a bag, plus the discount code if it may be used.
     *
     * @param  array<int, array{product_id:int, price:int, quantity:int}>  $lines  unit prices after sales
     * @return array{amount:int, lines: array<int, array{type:string, id:?int, title:string, amount:int}>,
     *               free_shipping:bool, code_used:bool, code_blocked:bool, promotions: array<int, array{id:int, amount:int}>}
     */
    public function discountsFor(array $lines, ?Discount $code): array
    {
        $subtotal = array_sum(array_map(fn ($l) => $l['price'] * $l['quantity'], $lines));
        $auto = [];

        // Spend-more tiers: the single best tier across tiered promotions
        $bestTier = null;
        foreach ($this->active()->where('type', 'tiered') as $p) {
            $tier = collect($this->tiers($p))->filter(fn ($t) => $subtotal >= (int) $t['min'])->sortByDesc('min')->first();
            if (! $tier) {
                continue;
            }
            $off = $this->tierOff($p, $tier, $subtotal);
            if ($off > ($bestTier['amount'] ?? 0)) {
                $bestTier = ['promotion' => $p, 'amount' => $off];
            }
        }
        if ($bestTier) {
            $auto[] = $bestTier;
        }

        // Bundles: complete sets of the bundle's products
        $qty = [];
        $price = [];
        foreach ($lines as $l) {
            $qty[$l['product_id']] = ($qty[$l['product_id']] ?? 0) + $l['quantity'];
            $price[$l['product_id']] = min($price[$l['product_id']] ?? PHP_INT_MAX, $l['price']);   // cheapest variant counts
        }
        foreach ($this->active()->where('type', 'bundle') as $p) {
            $ids = array_map('intval', $p->config['product_ids'] ?? []);
            if (count($ids) < 2) {
                continue;
            }
            $sets = min(array_map(fn ($id) => $qty[$id] ?? 0, $ids));
            if ($sets < 1) {
                continue;
            }
            $setPrice = array_sum(array_map(fn ($id) => $price[$id], $ids));
            $off = $p->reduce($setPrice * $sets, $sets);
            if ($off > 0) {
                $auto[] = ['promotion' => $p, 'amount' => $off];
            }
        }

        $autoTotal = min($subtotal, array_sum(array_column($auto, 'amount')));
        $codeOff = $code ? $code->amountFor(max(0, $subtotal)) : 0;
        $allCombine = collect($auto)->every(fn ($a) => $a['promotion']->combines_with_codes);

        $useCode = (bool) $code;
        $blocked = false;
        if ($code && ! $allCombine && $code->type !== 'free_shipping') {
            // Can't stack: the shopper gets whichever saves more
            if ($codeOff > $autoTotal) {
                $auto = array_values(array_filter($auto, fn ($a) => $a['promotion']->combines_with_codes));
            } else {
                $useCode = false;
                $blocked = true;
            }
            $autoTotal = min($subtotal, array_sum(array_column($auto, 'amount')));
        }
        if ($useCode) {
            $codeOff = $code->amountFor(max(0, $subtotal - $autoTotal));   // code applies to what's left
        }

        $out = array_map(fn ($a) => ['type' => 'promotion', 'id' => $a['promotion']->id, 'title' => $a['promotion']->title, 'amount' => $a['amount']], $auto);
        if ($useCode && $codeOff > 0) {
            $out[] = ['type' => 'code', 'id' => $code->id, 'title' => $code->code, 'amount' => $codeOff];
        }
        $amount = min($subtotal, $autoTotal + ($useCode ? $codeOff : 0));

        return [
            'amount' => $amount,
            'lines' => $out,
            'free_shipping' => $useCode && $code?->type === 'free_shipping',
            'code_used' => $useCode,
            'code_blocked' => $blocked,
            'promotions' => array_map(fn ($a) => ['id' => $a['promotion']->id, 'amount' => $a['amount']], $auto),
        ];
    }

    /** "Spend :amount more to save :value" — the next tier the bag hasn't reached. */
    public function nextTier(int $subtotal): ?array
    {
        $next = null;
        foreach ($this->active()->where('type', 'tiered') as $p) {
            foreach ($this->tiers($p) as $t) {
                if ((int) $t['min'] > $subtotal && (! $next || (int) $t['min'] < $next['min'])) {
                    $next = ['min' => (int) $t['min'], 'missing' => (int) $t['min'] - $subtotal, 'value' => (float) $t['value'], 'value_type' => $p->value_type];
                }
            }
        }

        return $next;
    }

    private function tierOff(Promotion $p, array $tier, int $subtotal): int
    {
        $value = (float) $tier['value'];
        $off = $p->value_type === 'percentage' ? (int) round($subtotal * min(100, $value) / 100) : (int) round($value * 100);

        return min($subtotal, max(0, $off));
    }
}
