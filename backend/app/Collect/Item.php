<?php

namespace App\Collect;

use Illuminate\Support\Str;

/**
 * The normalized shape of a collected product (collected_items.data / original):
 *
 *   title, description (HTML), vendor, product_type, tags[], images[] (URLs), currency (of the costs),
 *   options:  [{name, values[]}]                                    max 3
 *   variants: [{options: {Name: Value}, sku, cost, compare_cost,    cost = source price (minor units, source currency)
 *               price, compare_at_price,                            selling price (minor units, store currency), set by Pricing
 *               inventory, weight, image}]                          weight in grams, image = URL
 *   pricing:  {rule, rate} once priced
 */
final class Item
{
    public const MAX_IMAGES = 12;

    public const MAX_VARIANTS = 100;

    public static function normalize(array $d): array
    {
        $options = [];
        foreach (array_slice((array) ($d['options'] ?? []), 0, 3) as $o) {
            $name = Str::limit(trim((string) ($o['name'] ?? '')), 60, '');
            $values = array_values(array_unique(array_filter(array_map(fn ($v) => Str::limit(trim((string) $v), 100, ''), (array) ($o['values'] ?? [])), 'strlen')));
            if ($name !== '' && $values) {
                $options[] = ['name' => $name, 'values' => $values];
            }
        }
        $names = array_column($options, 'name');

        $variants = [];
        foreach (array_slice((array) ($d['variants'] ?? []), 0, self::MAX_VARIANTS) as $v) {
            $opts = [];
            foreach ($names as $i => $n) {
                $val = trim((string) (($v['options'] ?? [])[$n] ?? ''));
                $opts[$n] = $val !== '' ? $val : $options[$i]['values'][0];
            }
            $variants[] = [
                'options' => $opts,
                'sku' => self::str($v['sku'] ?? null, 100),
                'cost' => self::int($v['cost'] ?? null) ?? 0,
                'compare_cost' => self::int($v['compare_cost'] ?? null),
                'price' => self::int($v['price'] ?? null),
                'compare_at_price' => self::int($v['compare_at_price'] ?? null),
                'inventory' => self::int($v['inventory'] ?? null),
                'weight' => self::int($v['weight'] ?? null),
                'image' => self::url($v['image'] ?? null),
            ];
        }
        if (! $options) {
            $variants = [($variants[0] ?? ['options' => [], 'sku' => null, 'cost' => 0, 'compare_cost' => null, 'price' => null,
                'compare_at_price' => null, 'inventory' => null, 'weight' => null, 'image' => null])];
            $variants[0]['options'] = [];
        } else {
            // One variant per combination (first wins)
            $variants = array_values(collect($variants)->unique(fn ($v) => json_encode($v['options']))->all());
        }

        $images = array_values(array_unique(array_filter(array_map(fn ($u) => self::url($u), (array) ($d['images'] ?? [])))));

        return array_filter([
            'title' => Str::limit(trim((string) ($d['title'] ?? '')), 255, ''),
            'description' => (string) ($d['description'] ?? ''),
            'vendor' => self::str($d['vendor'] ?? null, 120),
            'product_type' => self::str($d['product_type'] ?? null, 120),
            'tags' => array_values(array_unique(array_filter(array_map(fn ($t) => Str::limit(trim((string) $t), 60, ''), (array) ($d['tags'] ?? [])), 'strlen'))),
            'images' => array_slice($images, 0, 40),
            'currency' => strtoupper(self::str($d['currency'] ?? null, 3) ?? 'USD'),
            'options' => $options,
            'variants' => $variants,
            'pricing' => $d['pricing'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /** "1.234,56" / "1,234.56" / "1234.5" / "¥ 28.00" → minor units. */
    public static function money(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            return (int) round($v * 100);
        }
        $s = preg_replace('/[^\d.,]/', '', (string) $v);
        if ($s === '') {
            return null;
        }
        $lastDot = strrpos($s, '.');
        $lastComma = strrpos($s, ',');
        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            // Comma is the decimal separator only when followed by 1–2 digits ("12,90"); "1,234" is thousands
            $s = preg_match('/,\d{1,2}$/', $s) ? str_replace(['.', ','], ['', '.'], $s) : str_replace(',', '', $s);
        } else {
            $s = str_replace(',', '', $s);
        }

        return is_numeric($s) ? (int) round(((float) $s) * 100) : null;
    }

    public static function url(mixed $u): ?string
    {
        $u = trim((string) $u);
        if (str_starts_with($u, '//')) {
            $u = 'https:'.$u;
        }

        return preg_match('#^https?://[^\s"<>]+$#i', $u) && strlen($u) <= 2000 ? $u : null;
    }

    /** Normalized URL for duplicate checks: no fragment, no tracking query, lower-case host. */
    public static function hash(string $url): string
    {
        $p = parse_url(trim($url)) ?: [];
        // Shopify-style "/collections/x/products/handle?variant=1" is the same product as "/products/handle"
        if (preg_match('#/products/([^/?\#]+)#', $p['path'] ?? '', $m)) {
            $p['path'] = '/products/'.strtolower($m[1]);
            $p['query'] = '';
        }
        parse_str($p['query'] ?? '', $q);
        $q = array_filter($q, fn ($k) => ! preg_match('/^(utm_|spm|scm|pvid|_t|ref|gclid|fbclid)/i', (string) $k), ARRAY_FILTER_USE_KEY);
        ksort($q);
        $norm = strtolower($p['host'] ?? '').rtrim($p['path'] ?? '', '/').($q ? '?'.http_build_query($q) : '');

        return sha1(preg_replace('#^www\.#', '', $norm));
    }

    private static function int(mixed $v): ?int
    {
        return is_numeric($v) ? max(0, (int) $v) : null;
    }

    private static function str(mixed $v, int $max): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : Str::limit($v, $max, '');
    }
}
