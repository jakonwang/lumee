<?php

namespace App\Collect;

use App\LeadFinder\SafeFetcher;

/**
 * Turns what a page publishes into App\Collect\Item data. Only public, structured data is read:
 * Shopify's product JSON, WooCommerce's Store API, schema.org JSON-LD and Open Graph tags,
 * or what the browser extension picked from the page the merchant has open.
 */
final class Parsers
{
    /** Shopify /products/{handle}.json (product) or one entry of /products.json. */
    public static function shopify(array $p, string $currency): array
    {
        $images = collect($p['images'] ?? [])->mapWithKeys(fn ($i) => [(int) ($i['id'] ?? 0) => (string) ($i['src'] ?? '')]);
        $options = collect($p['options'] ?? [])->map(fn ($o) => ['name' => is_array($o) ? ($o['name'] ?? '') : (string) $o, 'values' => $o['values'] ?? []])
            ->reject(fn ($o) => $o['name'] === 'Title' && ($o['values'] === ['Default Title'] || $o['values'] === []))->values();
        $names = $options->pluck('name')->all();

        $variants = [];
        foreach ($p['variants'] ?? [] as $v) {
            $opts = [];
            foreach ($names as $i => $n) {
                $opts[$n] = (string) ($v['option'.($i + 1)] ?? '');
            }
            // Options listed only on variants (products.json lists option names as strings)
            $variants[] = [
                'options' => $opts,
                'sku' => $v['sku'] ?? null,
                'cost' => Item::money($v['price'] ?? null),
                'compare_cost' => Item::money($v['compare_at_price'] ?? null),
                'weight' => isset($v['grams']) ? (int) $v['grams'] : null,
                'image' => $v['featured_image']['src'] ?? ($images[(int) ($v['image_id'] ?? 0)] ?? null),
            ];
        }
        $options = $options->map(fn ($o, $i) => [
            'name' => $o['name'],
            'values' => $o['values'] ?: array_values(array_unique(array_map(fn ($v) => $v['options'][$o['name']] ?? '', $variants))),
        ])->all();

        $tags = $p['tags'] ?? [];

        return Item::normalize([
            'title' => $p['title'] ?? '',
            'description' => $p['body_html'] ?? '',
            'vendor' => $p['vendor'] ?? null,
            'product_type' => $p['product_type'] ?? null,
            'tags' => is_array($tags) ? $tags : array_map('trim', explode(',', (string) $tags)),
            'images' => $images->values()->all(),
            'currency' => $currency,
            'options' => $options,
            'variants' => $variants,
        ]);
    }

    /** WooCommerce Store API product (wp-json/wc/store/v1/products). */
    public static function woo(array $p): array
    {
        $prices = $p['prices'] ?? [];
        $unit = (int) ($prices['currency_minor_unit'] ?? 2);
        $toMinor = fn ($v) => is_numeric($v) ? (int) round(((int) $v) * 100 / (10 ** $unit)) : null;
        $price = $toMinor($prices['price'] ?? null) ?? 0;
        $regular = $toMinor($prices['regular_price'] ?? null);

        $options = collect($p['attributes'] ?? [])->filter(fn ($a) => ! empty($a['has_variations']))
            ->map(fn ($a) => ['name' => (string) $a['name'], 'values' => collect($a['terms'] ?? [])->pluck('name')->all()])->values()->all();
        $variants = [];
        foreach ($p['variations'] ?? [] as $v) {
            $opts = [];
            foreach ($v['attributes'] ?? [] as $a) {
                $name = collect($options)->first(fn ($o) => strcasecmp($o['name'], (string) $a['name']) === 0)['name'] ?? null;
                $term = collect($options)->firstWhere('name', $name)['values'] ?? [];
                // Variation attributes use the term slug; map back to the display name when possible
                $value = collect($term)->first(fn ($t) => \Illuminate\Support\Str::slug($t) === \Illuminate\Support\Str::slug((string) $a['value'])) ?? (string) $a['value'];
                $name && $opts[$name] = $value;
            }
            $variants[] = ['options' => $opts, 'cost' => $price, 'compare_cost' => $regular > $price ? $regular : null];
        }

        return Item::normalize([
            'title' => html_entity_decode((string) ($p['name'] ?? ''), ENT_QUOTES),
            'description' => (string) ($p['description'] ?? ''),
            'tags' => collect($p['tags'] ?? [])->pluck('name')->all(),
            'product_type' => collect($p['categories'] ?? [])->pluck('name')->first(),
            'images' => collect($p['images'] ?? [])->pluck('src')->all(),
            'currency' => $prices['currency_code'] ?? 'USD',
            'options' => $options,
            'variants' => $variants ?: [['options' => [], 'sku' => $p['sku'] ?? null, 'cost' => $price, 'compare_cost' => $regular > $price ? $regular : null]],
        ]);
    }

    /** Any product page: schema.org JSON-LD first, then Open Graph / product meta tags. Null = no product found. */
    public static function page(string $html, string $url, string $currency): ?array
    {
        return self::jsonLd($html, $url, $currency) ?? self::openGraph($html, $url, $currency);
    }

    /** What the browser extension read off the page when the page itself publishes no product data. */
    public static function extracted(array $e, string $url, string $currency): ?array
    {
        $title = trim((string) ($e['title'] ?? ''));
        if ($title === '') {
            return null;
        }

        return Item::normalize([
            'title' => $title,
            'description' => (string) ($e['description'] ?? ''),
            'images' => array_map(fn ($i) => SafeFetcher::absolute((string) $i, $url), (array) ($e['images'] ?? [])),
            'currency' => $e['currency'] ?? $currency,
            'variants' => [['options' => [], 'cost' => Item::money($e['price'] ?? null) ?? 0]],
        ]);
    }

    private static function jsonLd(string $html, string $url, string $currency): ?array
    {
        preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m);
        $nodes = [];
        $walk = function ($n) use (&$walk, &$nodes) {
            if (! is_array($n)) {
                return;
            }
            if (array_is_list($n)) {
                array_map($walk, $n);

                return;
            }
            $type = (array) ($n['@type'] ?? []);
            if (array_intersect($type, ['Product', 'ProductGroup'])) {
                $nodes[] = $n;
            }
            isset($n['@graph']) && $walk($n['@graph']);
        };
        foreach ($m[1] as $json) {
            $walk(json_decode(html_entity_decode(trim($json), ENT_QUOTES | ENT_HTML5), true));
        }
        // A ProductGroup (with variants) beats a single Product
        usort($nodes, fn ($a, $b) => in_array('ProductGroup', (array) $b['@type'], true) <=> in_array('ProductGroup', (array) $a['@type'], true));
        $p = $nodes[0] ?? null;
        if (! $p || trim((string) ($p['name'] ?? '')) === '') {
            return null;
        }

        $images = self::images($p['image'] ?? [], $url);
        $offer = self::offer($p['offers'] ?? null);
        $cur = $offer['currency'] ?? $currency;

        $options = [];
        $variants = [];
        foreach ((array) ($p['hasVariant'] ?? []) as $v) {
            if (! is_array($v)) {
                continue;
            }
            $opts = [];
            foreach (['color' => 'Color', 'size' => 'Size', 'material' => 'Material', 'pattern' => 'Pattern'] as $key => $name) {
                if (is_scalar($v[$key] ?? null) && trim((string) $v[$key]) !== '') {
                    $opts[$name] = trim((string) $v[$key]);
                }
            }
            foreach ((array) ($v['additionalProperty'] ?? []) as $prop) {
                if (is_array($prop) && ! empty($prop['name']) && is_scalar($prop['value'] ?? null)) {
                    $opts[ucfirst((string) $prop['name'])] = (string) $prop['value'];
                }
            }
            $o = self::offer($v['offers'] ?? null);
            $cur = $o['currency'] ?? $cur;
            $variants[] = ['options' => $opts, 'sku' => $v['sku'] ?? null, 'cost' => $o['price'] ?? $offer['price'] ?? 0,
                'image' => self::images($v['image'] ?? [], $url)[0] ?? null];
            foreach ($opts as $n => $val) {
                $options[$n][] = $val;
            }
            array_push($images, ...self::images($v['image'] ?? [], $url));
        }
        $options = collect($options)->map(fn ($vals, $n) => ['name' => $n, 'values' => array_values(array_unique($vals))])->values()->take(3)->all();

        return Item::normalize([
            'title' => html_entity_decode((string) $p['name'], ENT_QUOTES),
            'description' => (string) ($p['description'] ?? ''),
            'vendor' => is_array($p['brand'] ?? null) ? ($p['brand']['name'] ?? null) : ($p['brand'] ?? null),
            'product_type' => is_string($p['category'] ?? null) ? $p['category'] : null,
            'images' => $images,
            'currency' => $cur,
            'options' => $options,
            'variants' => $variants ?: [['options' => [], 'sku' => $p['sku'] ?? null, 'cost' => $offer['price'] ?? 0]],
        ]);
    }

    private static function openGraph(string $html, string $url, string $currency): ?array
    {
        $meta = [];
        preg_match_all('#<meta\s+[^>]*>#i', $html, $tags);
        foreach ($tags[0] as $tag) {
            if (preg_match('#(?:property|name|itemprop)=["\']([^"\']+)["\']#i', $tag, $k) && preg_match('#content=["\']([^"\']*)["\']#i', $tag, $v)) {
                $meta[strtolower($k[1])][] = html_entity_decode($v[1], ENT_QUOTES);
            }
        }
        $title = $meta['og:title'][0] ?? null;
        $price = $meta['product:price:amount'][0] ?? $meta['og:price:amount'][0] ?? $meta['price'][0] ?? null;
        // Only a product page (has a price) — a blog post with og:title is not a product
        if (! $title || $price === null) {
            return null;
        }

        return Item::normalize([
            'title' => $title,
            'description' => $meta['og:description'][0] ?? $meta['description'][0] ?? '',
            'images' => array_map(fn ($i) => SafeFetcher::absolute($i, $url), $meta['og:image'] ?? []),
            'currency' => $meta['product:price:currency'][0] ?? $meta['og:price:currency'][0] ?? $meta['pricecurrency'][0] ?? $currency,
            'variants' => [['options' => [], 'cost' => Item::money($price) ?? 0]],
        ]);
    }

    /** @return array{price?: int, currency?: string} */
    private static function offer(mixed $offers): array
    {
        if (! is_array($offers)) {
            return [];
        }
        $o = array_is_list($offers) ? ($offers[0] ?? []) : $offers;
        $price = $o['price'] ?? $o['lowPrice'] ?? ($o['priceSpecification']['price'] ?? null);

        return array_filter(['price' => Item::money($price), 'currency' => is_string($o['priceCurrency'] ?? null) ? strtoupper($o['priceCurrency']) : null], fn ($v) => $v !== null);
    }

    private static function images(mixed $img, string $base): array
    {
        $out = [];
        foreach (is_array($img) && array_is_list($img) ? $img : [$img] as $i) {
            $u = is_array($i) ? ($i['url'] ?? $i['contentUrl'] ?? null) : $i;
            if (is_string($u) && $u !== '') {
                $out[] = SafeFetcher::absolute($u, $base);
            }
        }

        return $out;
    }
}
