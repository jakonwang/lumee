<?php

namespace App\Catalog;

use App\Models\Collection;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Smart (automated) collections: membership comes from rules instead of hand-picked products.
 * Members are written to the normal collection_product table, so storefront pages, discounts and
 * promotions that target a collection work the same for both kinds.
 *
 * Rules: [{field, op, value}], matched with "all" or "any".
 *   tag      equals                     (case-insensitive)
 *   type     equals                     (product type)
 *   vendor   equals
 *   title    contains
 *   price    gt | lt                    (lowest variant price, major units)
 *   stock    in_stock | out_of_stock
 *
 * Synced when a collection's rules change, when a product is saved, and when a variant's stock
 * crosses zero.
 */
class SmartCollections
{
    public const FIELDS = [
        'tag' => ['equals'], 'type' => ['equals'], 'vendor' => ['equals'], 'title' => ['contains'],
        'price' => ['gt', 'lt'], 'stock' => ['in_stock', 'out_of_stock'],
    ];

    public static function validRule(array $r): bool
    {
        $field = $r['field'] ?? null;
        $op = $r['op'] ?? null;
        if (! isset(self::FIELDS[$field]) || ! in_array($op, self::FIELDS[$field], true)) {
            return false;
        }
        if ($field === 'stock') {
            return true;
        }
        $v = $r['value'] ?? null;

        return $field === 'price' ? is_numeric($v) && $v >= 0 : is_string($v) && trim($v) !== '' && mb_strlen($v) <= 100;
    }

    public function matches(Collection $c, Product $p): bool
    {
        $rules = $c->rules ?? [];
        if (! $rules) {
            return false;
        }
        $p->loadMissing('variants:id,product_id,price,inventory_quantity');
        $results = array_map(fn ($r) => $this->test($r, $p), $rules);

        return $c->rules_match === 'any' ? in_array(true, $results, true) : ! in_array(false, $results, true);
    }

    private function test(array $r, Product $p): bool
    {
        $lc = fn ($s) => mb_strtolower(trim((string) $s));
        $raw = fn () => $p->variants->min(fn ($v) => (int) $v->getRawOriginal('price'));

        return match ($r['field']) {
            'tag' => in_array($lc($r['value']), array_map($lc, (array) ($p->tags ?? [])), true),
            'type' => $lc($p->getRawOriginal('product_type') ?? $p->product_type) === $lc($r['value']),
            'vendor' => $lc($p->vendor) === $lc($r['value']),
            'title' => str_contains($lc($p->getRawOriginal('title') ?? $p->title), $lc($r['value'])),
            'price' => $r['op'] === 'gt' ? $raw() > round($r['value'] * 100) : $raw() < round($r['value'] * 100),
            'stock' => ($p->variants->sum('inventory_quantity') > 0) === ($r['op'] === 'in_stock'),
            default => false,
        };
    }

    /** Rebuild one smart collection's members (keeps the order of products already in it). */
    public function sync(Collection $c): int
    {
        if (! $c->rules) {
            return 0;
        }
        $ids = [];
        Product::withoutGlobalScope('store')->where('store_id', $c->store_id)->with('variants:id,product_id,price,inventory_quantity')
            ->chunkById(500, function ($products) use ($c, &$ids) {
                foreach ($products as $p) {
                    if ($this->matches($c, $p)) {
                        $ids[] = $p->id;
                    }
                }
            });
        $current = DB::table('collection_product')->where('collection_id', $c->id)->orderBy('position')->pluck('product_id')->all();
        DB::table('collection_product')->where('collection_id', $c->id)->whereNotIn('product_id', $ids)->delete();
        $pos = count($current);
        foreach (array_diff($ids, $current) as $id) {
            DB::table('collection_product')->insert(['collection_id' => $c->id, 'product_id' => $id, 'position' => $pos++, 'store_id' => $c->store_id]);
        }

        return count($ids);
    }

    /** One product changed: add it to / remove it from every smart collection of its store. */
    public function syncProduct(Product $p): void
    {
        $smart = Collection::withoutGlobalScope('store')->where('store_id', $p->store_id)->whereNotNull('rules')->get();
        foreach ($smart as $c) {
            $in = DB::table('collection_product')->where('collection_id', $c->id)->where('product_id', $p->id)->exists();
            $should = $this->matches($c, $p);
            if ($should && ! $in) {
                $pos = (int) DB::table('collection_product')->where('collection_id', $c->id)->max('position') + 1;
                DB::table('collection_product')->insert(['collection_id' => $c->id, 'product_id' => $p->id, 'position' => $pos, 'store_id' => $c->store_id]);
            } elseif (! $should && $in) {
                DB::table('collection_product')->where('collection_id', $c->id)->where('product_id', $p->id)->delete();
            }
        }
    }
}
