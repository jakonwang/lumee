<?php

namespace App\Catalog;

use App\Models\Cart;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Frequently bought together" and cart suggestions. A product's companions are the ones the merchant
 * picked (products.upsell_ids), else the products most often bought in the same orders (last 180 days,
 * cached for 6 hours). Only active, buyable products with a single variant are offered for one-click
 * add; others link to their page.
 */
class Upsells
{
    public const COLS = 'variants:id,product_id,price,compare_at_price,inventory_quantity,position,title';

    /** @return int[] */
    public function companionIds(Product $p, int $limit = 3): array
    {
        $picked = array_values(array_filter(array_map('intval', (array) ($p->upsell_ids ?? []))));
        if ($picked) {
            return array_slice($picked, 0, $limit);
        }

        return Cache::remember("upsells:{$p->store_id}:{$p->id}", now()->addHours(6), fn () => DB::table('order_items as a')
            ->join('order_items as b', fn ($j) => $j->on('a.order_id', '=', 'b.order_id')->on('b.product_id', '!=', 'a.product_id'))
            ->join('orders as o', 'o.id', '=', 'a.order_id')
            ->where('o.store_id', $p->store_id)->whereNull('o.cancelled_at')->where('o.created_at', '>=', now()->subDays(180))
            ->where('a.product_id', $p->id)->whereNotNull('b.product_id')
            ->groupBy('b.product_id')->orderByRaw('COUNT(DISTINCT a.order_id) DESC')->limit(10)->pluck('b.product_id')->map(fn ($id) => (int) $id)->all());
    }

    public function forProduct(Product $p, int $limit = 3): Collection
    {
        return $this->load($this->companionIds($p, 10), [$p->id], $limit);
    }

    public function forCart(?Cart $cart, int $limit = 4): Collection
    {
        if (! $cart || $cart->items->isEmpty()) {
            return collect();
        }
        $inCart = $cart->items->map(fn ($i) => $i->variant?->product_id)->filter()->unique()->values()->all();
        $ids = [];
        foreach (Product::whereIn('id', $inCart)->get() as $p) {
            array_push($ids, ...$this->companionIds($p, 6));
        }

        return $this->load(array_values(array_unique($ids)), $inCart, $limit);
    }

    private function load(array $ids, array $exclude, int $limit): Collection
    {
        $ids = array_values(array_diff($ids, $exclude));
        if (! $ids) {
            return collect();
        }
        $products = Product::active()->with(self::COLS)->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn ($id) => $products->get($id))->filter()
            ->filter(fn (Product $p) => $p->variants->contains(fn ($v) => Preorders::config($p)['enabled'] || $v->inventory_quantity > 0))
            ->take($limit)->values();
    }

    /** The variant to add in one click, or null when the shopper must choose options. */
    public static function quickVariant(Product $p): ?int
    {
        return $p->variants->count() === 1 ? $p->variants->first()->id : null;
    }
}
