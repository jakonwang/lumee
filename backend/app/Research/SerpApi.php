<?php

namespace App\Research;

use App\Currency\ExchangeRates;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Outside evidence through SerpApi (serpapi.com, the merchant's own key, billed per search):
 * Google Trends interest over the last 3 months, Google Shopping results (how many sellers,
 * at what prices) in the market's country, and Google web results (lead finder channels).
 */
class SerpApi
{
    public const ENDPOINT = 'https://serpapi.com/search.json';

    public function __construct(private Store $store, private string $key) {}

    public static function for(Store $store): ?self
    {
        $key = Radar::serpApiKey($store);

        return $key ? new self($store, $key) : null;
    }

    /** @return array{average: int, growth: ?float, series: int[]} */
    public function trend(string $keyword, ?string $country): array
    {
        $json = $this->get(['engine' => 'google_trends', 'q' => $keyword, 'data_type' => 'TIMESERIES', 'date' => 'today 3-m', 'geo' => $country ?? '']);
        $series = array_map(fn ($p) => (int) ($p['values'][0]['extracted_value'] ?? 0), (array) ($json['interest_over_time']['timeline_data'] ?? []));
        if (! $series) {
            return ['average' => 0, 'growth' => null, 'series' => []];
        }
        // Weekly points: the last 4 against the 4 before them
        $recent = array_slice($series, -4);
        $before = array_slice($series, -8, 4);
        $avg = fn (array $a) => $a ? array_sum($a) / count($a) : 0;
        $growth = $avg($before) > 0 ? round(($avg($recent) - $avg($before)) / $avg($before) * 100, 1) : null;

        return ['average' => (int) round($avg($series)), 'growth' => $growth, 'series' => array_slice($series, -26)];
    }

    /** @return array{results: int, sellers: int, min: ?int, median: ?int, max: ?int, currency: ?string, store_median: ?int, examples: array} */
    public function shopping(string $keyword, string $country, string $language, string $marketCurrency = 'USD'): array
    {
        $json = $this->get(['engine' => 'google_shopping', 'q' => $keyword, 'gl' => strtolower($country), 'hl' => $language]);
        $results = (array) ($json['shopping_results'] ?? []);
        $prices = array_values(array_filter(array_map(fn ($r) => isset($r['extracted_price']) ? (int) round($r['extracted_price'] * 100) : null, $results)));
        sort($prices);
        $currency = $this->currency($results, $marketCurrency);
        $median = $prices ? $prices[intdiv(count($prices), 2)] : null;

        return [
            'results' => count($results),
            'sellers' => count(array_unique(array_filter(array_map(fn ($r) => mb_strtolower((string) ($r['source'] ?? '')), $results)))),
            'min' => $prices[0] ?? null, 'median' => $median, 'max' => $prices ? end($prices) : null, 'currency' => $currency,
            // What the store's own products sell for, in the same currency (for the price-fit score)
            'store_median' => $this->storeMedian($currency),
            'examples' => array_map(fn ($r) => ['title' => mb_substr((string) ($r['title'] ?? ''), 0, 120), 'price' => $r['price'] ?? null,
                'source' => $r['source'] ?? null, 'thumbnail' => $r['thumbnail'] ?? null], array_slice($results, 0, 6)),
        ];
    }

    /** Google web results (organic_results) for a query in a country and language. */
    public function google(string $q, string $country, string $language, int $start = 0): array
    {
        return $this->get(['engine' => 'google', 'q' => $q, 'gl' => strtolower($country), 'hl' => $language, 'start' => $start ?: null]);
    }

    private function get(array $params): array
    {
        $res = Http::timeout(30)->retry(1, 500, throw: false)->get(self::ENDPOINT, [...$params, 'api_key' => $this->key]);
        $json = $res->json() ?? [];
        if (! $res->successful() || isset($json['error'])) {
            $err = (string) ($json['error'] ?? 'HTTP '.$res->status());
            throw new RuntimeException(match (true) {
                $res->status() === 401 || str_contains($err, 'Invalid API key') => 'SerpApi Key 无效',
                $res->status() === 429 || str_contains($err, 'run out of searches') => 'SerpApi 搜索额度已用完',
                str_contains($err, "hasn't returned any results") => '没有搜到数据',
                default => 'SerpApi 请求失败：'.mb_substr($err, 0, 120),
            });
        }

        return $json;
    }

    /**
     * Google Shopping doesn't name the currency; read it from the price text ("R$ 49,90", "€12").
     * A bare "$" is the local dollar/peso of the market (Mexico: pesos), else US dollars.
     */
    private function currency(array $results, string $marketCurrency): ?string
    {
        $text = (string) ($results[0]['price'] ?? '');
        foreach (['R$' => 'BRL', 'MX$' => 'MXN', 'US$' => 'USD', 'CA$' => 'CAD', 'A$' => 'AUD', 'NZ$' => 'NZD', 'S$' => 'SGD', 'HK$' => 'HKD',
            '€' => 'EUR', '£' => 'GBP', '¥' => 'JPY', 'Rp' => 'IDR', '₱' => 'PHP', '฿' => 'THB', 'RM' => 'MYR'] as $sym => $code) {
            if (str_contains($text, $sym)) {
                return $code;
            }
        }
        if (str_contains($text, '$')) {
            return in_array($marketCurrency, ['USD', 'MXN', 'CAD', 'AUD', 'NZD', 'SGD', 'HKD', 'ARS', 'CLP', 'COP'], true) ? $marketCurrency : 'USD';
        }

        return null;
    }

    private function storeMedian(?string $currency): ?int
    {
        $prices = Product::where('status', 'active')->join('product_variants', 'product_variants.product_id', '=', 'products.id')
            ->orderBy('product_variants.price')->pluck('product_variants.price')->all();
        if (! $prices || ! $currency) {
            return null;
        }
        $rate = app(ExchangeRates::class)->rate($this->store->baseCurrency(), $currency);

        return $rate ? (int) round($prices[intdiv(count($prices), 2)] * $rate) : null;
    }
}
