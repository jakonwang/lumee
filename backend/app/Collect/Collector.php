<?php

namespace App\Collect;

use App\LeadFinder\SafeFetcher;
use RuntimeException;
use Throwable;

/**
 * Fetches public product data for a URL (server side, through SafeFetcher: public hosts only,
 * no login, no getting around bot protection — pages that block us are reported as failed and
 * can be collected with the browser extension instead).
 */
class Collector
{
    public const MAX_STORE_PRODUCTS = 500;

    public function __construct(private SafeFetcher $fetcher) {}

    /**
     * One product page.
     *
     * @return array{platform: string, data: array}
     *
     * @throws RuntimeException merchant-readable reason
     */
    public function product(string $url, string $currency): array
    {
        // Shopify store: the product's own JSON (complete variants and images)
        if ($handle = self::shopifyHandle($url)) {
            $origin = self::origin($url);
            try {
                $json = json_decode($this->fetcher->get($origin.'/products/'.rawurlencode($handle).'.json', 10, true)['html'], true);
                if (is_array($json['product'] ?? null)) {
                    return ['platform' => 'shopify', 'data' => Parsers::shopify($json['product'], $this->shopifyCurrency($origin) ?? $currency)];
                }
            } catch (Throwable) {
                // not a Shopify store after all (or the JSON is turned off): read the page
            }
        }

        $page = $this->fetcher->get($url, 12);

        // WooCommerce: its public Store API has prices with the currency and every variation
        if (str_contains($page['html'], 'woocommerce') && ($slug = self::lastSegment($page['url']))) {
            try {
                $list = json_decode($this->fetcher->get(self::origin($page['url']).'/wp-json/wc/store/v1/products?slug='.rawurlencode($slug), 10, true)['html'], true);
                if (is_array($list[0] ?? null)) {
                    $p = $list[0];
                    $variations = [];
                    foreach (array_slice($p['variations'] ?? [], 0, 50) as $v) {
                        $variations[] = $v;
                    }
                    $p['variations'] = $variations;

                    return ['platform' => 'woocommerce', 'data' => Parsers::woo($p)];
                }
            } catch (Throwable) {
                // Store API closed: fall back to the page's structured data
            }
        }

        $data = Parsers::page($page['html'], $page['url'], $currency);
        if (! $data) {
            throw new RuntimeException('页面上没有找到商品数据（可以用浏览器插件采集这个页面）');
        }

        return ['platform' => 'page', 'data' => $data];
    }

    /**
     * Every product of a Shopify store (public /products.json), up to MAX_STORE_PRODUCTS.
     *
     * @return array<int, array{url: string, data: array}>
     */
    public function shopifyStore(string $url, string $currency): array
    {
        $origin = self::origin($url);
        $currency = $this->shopifyCurrency($origin) ?? $currency;
        $out = [];
        for ($page = 1; count($out) < self::MAX_STORE_PRODUCTS && $page <= 20; $page++) {
            try {
                $json = json_decode($this->fetcher->get($origin.'/products.json?limit=50&page='.$page, 15, true)['html'], true);
            } catch (Throwable $e) {
                if ($page === 1) {
                    throw new RuntimeException('读取不到这个店铺的商品列表（不是 Shopify 店铺，或关闭了公开商品数据）：'.$e->getMessage());
                }
                break;
            }
            $products = $json['products'] ?? [];
            if (! $products) {
                break;
            }
            foreach ($products as $p) {
                if (! empty($p['handle'])) {
                    $out[] = ['url' => $origin.'/products/'.$p['handle'], 'data' => Parsers::shopify($p, $currency)];
                }
            }
        }

        return array_slice($out, 0, self::MAX_STORE_PRODUCTS);
    }

    /** "https://x.com/collections/a/products/rose-ring?variant=1" → "rose-ring" */
    public static function shopifyHandle(string $url): ?string
    {
        return preg_match('#/products/([^/?\#]+)#', (string) parse_url($url, PHP_URL_PATH), $m) ? rawurldecode($m[1]) : null;
    }

    public static function origin(string $url): string
    {
        $p = parse_url($url);

        return strtolower($p['scheme'] ?? 'https').'://'.strtolower($p['host'] ?? '');
    }

    private static function lastSegment(string $url): ?string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        return $path === '' ? null : basename($path);
    }

    /** The shop's currency (Shopify answers /cart.js publicly). */
    private function shopifyCurrency(string $origin): ?string
    {
        try {
            $c = json_decode($this->fetcher->get($origin.'/cart.js', 8, true)['html'], true)['currency'] ?? null;

            return is_string($c) && preg_match('/^[A-Z]{3}$/', $c) ? $c : null;
        } catch (Throwable) {
            return null;
        }
    }
}
