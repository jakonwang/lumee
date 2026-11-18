<?php

namespace App\Pricing;

use App\B2B\B2B;
use App\Models\PriceList;
use App\Models\PriceListPrice;

/**
 * Wholesale prices for the signed-in storefront buyer (request-scoped).
 * A buyer whose tags (their own, plus their company's tag) match enabled price lists gets:
 *  - the best % off across those lists,
 *  - fixed variant prices where set (they beat the %),
 *  - volume tiers (buy 10+ → lower unit price) and quantity rules (minimum, sold in multiples of),
 *  - the lowest minimum order subtotal of the lists.
 * ProductVariant::getAttribute('price') asks price(); CartItem/checkout ask unitPrice() for tiers.
 */
class PriceLists
{
    private ?array $resolved = null;

    private bool $loaded = false;

    /** For tests / non-HTTP code: price as a specific set of tags. */
    public function useTags(?array $tags): void
    {
        $this->resolved = $tags ? $this->load($tags) : null;
        $this->loaded = true;
    }

    public function reset(): void
    {
        $this->loaded = false;
        $this->resolved = null;
    }

    public function active(): ?array
    {
        if (! $this->loaded) {
            $this->loaded = true;
            $tags = app(B2B::class)->pricingTags();
            $this->resolved = $tags ? $this->load($tags) : null;
        }

        return $this->resolved;
    }

    /** Price for a variant before any sale or tier (minor units). */
    public function price(int $variantId, int $price): int
    {
        $a = $this->active();
        if (! $a || $price <= 0) {
            return $price;
        }
        if (isset($a['fixed'][$variantId])) {
            return min($price, $a['fixed'][$variantId]);
        }

        return $a['percent'] > 0 ? (int) round($price * (100 - $a['percent']) / 100) : $price;
    }

    /** Unit price for a quantity: the best volume tier reached, else the given price. */
    public function unitPrice(int $variantId, int $quantity, int $price): int
    {
        $best = $price;
        foreach ($this->active()['tiers'][$variantId] ?? [] as $tier) {
            if ($quantity >= $tier['min']) {
                $best = min($best, $tier['price']);
            }
        }

        return $best;
    }

    /** @return array{min:int, step:int, tiers: array<int, array{min:int, price:int}>} */
    public function rules(int $variantId): array
    {
        $a = $this->active();

        return ['min' => $a['min'][$variantId] ?? 1, 'step' => $a['step'][$variantId] ?? 1, 'tiers' => $a['tiers'][$variantId] ?? []];
    }

    /** null when the quantity is fine, else the reason (shopper-facing). */
    public function quantityError(int $variantId, int $quantity): ?string
    {
        $r = $this->rules($variantId);
        if ($quantity < $r['min']) {
            return __('The minimum order for this item is :count.', ['count' => $r['min']]);
        }
        if ($r['step'] > 1 && $quantity % $r['step'] !== 0) {
            return __('This item is sold in multiples of :count.', ['count' => $r['step']]);
        }

        return null;
    }

    /** Minimum order subtotal for the customer's wholesale pricing (0 = none). */
    public function minimum(): int
    {
        return (int) ($this->active()['min_subtotal'] ?? 0);
    }

    public function name(): ?string
    {
        return $this->active()['name'] ?? null;
    }

    private function load(array $tags): ?array
    {
        $tags = array_map(fn ($t) => mb_strtolower(trim((string) $t)), $tags);
        $lists = PriceList::where('enabled', true)->get()->filter(fn ($l) => in_array(mb_strtolower($l->customer_tag), $tags, true));
        if ($lists->isEmpty()) {
            return null;
        }
        $fixed = $min = $step = $tiers = [];
        foreach (PriceListPrice::whereIn('price_list_id', $lists->pluck('id'))->get() as $p) {
            $v = $p->variant_id;
            if ($p->price !== null) {
                $fixed[$v] = isset($fixed[$v]) ? min($fixed[$v], $p->price) : $p->price;
            }
            $min[$v] = max($min[$v] ?? 1, (int) $p->min_qty);
            $step[$v] = max($step[$v] ?? 1, (int) $p->step);
            foreach ((array) $p->tiers as $t) {
                if (isset($t['min'], $t['price'])) {
                    $tiers[$v][] = ['min' => (int) $t['min'], 'price' => (int) $t['price']];
                }
            }
        }
        foreach ($tiers as $v => $list) {
            usort($list, fn ($a, $b) => $a['min'] <=> $b['min']);
            $tiers[$v] = $list;
        }

        return [
            'name' => $lists->first()->name,
            'percent' => (float) $lists->max('percent_off'),
            'fixed' => $fixed, 'min' => $min, 'step' => $step, 'tiers' => $tiers,
            'min_subtotal' => (int) $lists->min('min_subtotal'),
        ];
    }
}
