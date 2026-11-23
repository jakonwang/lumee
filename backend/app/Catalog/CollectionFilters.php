<?php

namespace App\Catalog;

use App\Currency\Presentment;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;

/**
 * Filters on a collection page: option values (Size, Color…), product type, in stock, and a price range in the
 * shopper's currency. Worked out in PHP from the collection's variants, so it is the same on MySQL and SQLite and
 * uses the translated option names and the prices shoppers see.
 *
 * Query string: ?f[o][Color][]=Red&f[type][]=Rings&f[stock]=1&f[min]=10&f[max]=50
 * Counts are "how many products you'd get if you also picked this" (other groups' choices applied, not this one's).
 */
class CollectionFilters
{
    /** Bigger collections skip filters rather than load every variant. */
    public const MAX_PRODUCTS = 3000;

    private const MAX_VALUES = 40;

    public function __construct(private Presentment $px) {}

    /**
     * @param  Builder|Relation  $query  the collection's products (narrowed in place)
     * @return array{groups: list<array>, price: ?array, active: list<array>, any: bool}
     */
    public function apply($query, Request $request): array
    {
        $none = ['groups' => [], 'price' => null, 'active' => [], 'any' => false];
        // A deep copy (cloning a relation would share its query builder and lose the page's sort order)
        $base = $query instanceof Relation ? $query->getQuery()->clone() : $query->clone();
        $ids = $base->reorder()->pluck('products.id')->all();
        if (! $ids || count($ids) > self::MAX_PRODUCTS) {
            return $none;
        }

        $f = (array) $request->query('f', []);
        $picked = [];   // group key => list of values
        foreach ((array) ($f['o'] ?? []) as $name => $values) {
            $values = array_values(array_filter(array_map('strval', (array) $values), fn ($v) => $v !== ''));
            if ($values) {
                $picked['o:'.$name] = $values;
            }
        }
        if ($types = array_values(array_filter(array_map('strval', (array) ($f['type'] ?? []))))) {
            $picked['type'] = $types;
        }
        $stock = ! empty($f['stock']);
        $min = is_numeric($f['min'] ?? null) ? (int) round((float) $f['min'] * 100) : null;
        $max = is_numeric($f['max'] ?? null) ? (int) round((float) $f['max'] * 100) : null;

        // Variants with the shopper's price and (translated) options; product types
        $types = Product::whereIn('id', $ids)->pluck('product_type', 'id')->all();
        $rows = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (ProductVariant::whereIn('product_id', $chunk)->orderBy('position')->orderBy('id')->get() as $v) {
                $rows[] = ['p' => $v->product_id, 'o' => (array) $v->options, 'price' => $this->px->convert((int) $v->price),
                    'stock' => (int) $v->inventory_quantity > 0, 'type' => trim((string) ($types[$v->product_id] ?? ''))];
            }
        }

        // Does a variant pass every choice (except one group, for that group's counts)?
        $passes = function (array $r, ?string $except = null) use ($picked, $stock, $min, $max): bool {
            foreach ($picked as $group => $values) {
                if ($group === $except) {
                    continue;
                }
                $value = $group === 'type' ? $r['type'] : ($r['o'][substr($group, 2)] ?? null);
                if (! in_array((string) $value, $values, true)) {
                    return false;
                }
            }
            if ($stock && $except !== 'stock' && ! $r['stock']) {
                return false;
            }
            if ($except !== 'price' && (($min !== null && $r['price'] < $min) || ($max !== null && $r['price'] > $max))) {
                return false;
            }

            return true;
        };

        // Groups: every option name used, plus product type when there's more than one
        $groups = [];
        foreach ($rows as $r) {
            foreach ($r['o'] as $name => $value) {
                $groups['o:'.$name]['label'] ??= (string) $name;
                $groups['o:'.$name]['values'][(string) $value] ??= [];
            }
            if ($r['type'] !== '') {
                $groups['type']['label'] = __('Product type');
                $groups['type']['values'][$r['type']] ??= [];
            }
        }
        foreach ($rows as $r) {
            foreach ($groups as $key => $g) {
                $value = $key === 'type' ? $r['type'] : (string) ($r['o'][substr($key, 2)] ?? '');
                if ($value !== '' && $passes($r, $key)) {
                    $groups[$key]['values'][$value][$r['p']] = true;
                }
            }
        }
        $out = [];
        foreach ($groups as $key => $g) {
            $values = array_slice($g['values'], 0, self::MAX_VALUES, true);
            if (count($g['values']) < 2 && ! isset($picked[$key])) {
                continue;   // nothing to choose between
            }
            $out[] = [
                'key' => $key, 'label' => $g['label'],
                'param' => $key === 'type' ? 'f[type][]' : 'f[o]['.substr($key, 2).'][]',
                'values' => array_map(fn ($value, $products) => ['value' => (string) $value, 'count' => count($products),
                    'checked' => in_array((string) $value, $picked[$key] ?? [], true)], array_keys($values), $values),
            ];
        }
        usort($out, fn ($a, $b) => ($a['key'] === 'type') <=> ($b['key'] === 'type'));   // options first

        // Price range of what the other choices leave
        $prices = array_column(array_filter($rows, fn ($r) => $passes($r, 'price')), 'price');
        $price = $prices ? ['min' => intdiv(min($prices), 100), 'max' => (int) ceil(max($prices) / 100),
            'from' => $min !== null ? $min / 100 : null, 'to' => $max !== null ? $max / 100 : null, 'currency' => $this->px->currency()] : null;

        // Chips for what's picked, each with a link that removes it
        $active = [];
        foreach ($picked as $key => $values) {
            foreach ($values as $value) {
                $active[] = ['label' => $value, 'url' => $this->without($request, $key, $value)];
            }
        }
        if ($stock) {
            $active[] = ['label' => __('In stock'), 'url' => $this->without($request, 'stock')];
        }
        if ($min !== null || $max !== null) {
            $active[] = ['label' => $this->px->display($min ?? 0).' – '.($max !== null ? $this->px->display($max) : '∞'), 'url' => $this->without($request, 'price')];
        }

        $any = $picked || $stock || $min !== null || $max !== null;
        if ($any) {
            $keep = array_values(array_unique(array_column(array_filter($rows, fn ($r) => $passes($r)), 'p')));
            $query->whereIn('products.id', $keep ?: [0]);
        }

        return ['groups' => $out, 'price' => $price, 'active' => $active, 'any' => $any, 'stock' => $stock,
            'in_stock_count' => count(array_unique(array_column(array_filter($rows, fn ($r) => $r['stock'] && $passes($r, 'stock')), 'p')))];
    }

    /** This page's URL without one choice (and back on page 1). */
    private function without(Request $request, string $key, ?string $value = null): string
    {
        $q = $request->query();
        unset($q['page']);
        if ($key === 'stock') {
            unset($q['f']['stock']);
        } elseif ($key === 'price') {
            unset($q['f']['min'], $q['f']['max']);
        } else {
            $name = substr($key, 2);
            $list = $key === 'type' ? ($q['f']['type'] ?? []) : ($q['f']['o'][$name] ?? []);
            $list = array_values(array_filter((array) $list, fn ($v) => (string) $v !== $value));
            if ($key === 'type') {
                $q['f']['type'] = $list;
            } else {
                $q['f']['o'][$name] = $list;
            }
        }

        return $request->url().($q ? '?'.http_build_query($q) : '');
    }

    /** The page without any filters (sort kept). */
    public static function clearUrl(Request $request): string
    {
        $q = array_diff_key($request->query(), ['f' => 1, 'page' => 1]);

        return $request->url().($q ? '?'.http_build_query($q) : '');
    }
}
