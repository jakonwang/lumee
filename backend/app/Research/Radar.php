<?php

namespace App\Research;

use App\I18n\Localization;
use App\Models\Market;
use App\Models\Product;
use App\Models\ProductOpportunity;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Product research radar (选品雷达): finds product ideas per market from the store's own signals
 * (searches that found nothing, popular searches, product types that test well), adds outside
 * evidence (search trend and shopping competition via SerpApi, with the merchant's key), scores
 * them and asks Claude for a market analysis. Nothing is scraped: only official APIs.
 */
class Radar
{
    /** Score weights; missing evidence is left out and the rest re-weighted. */
    public const WEIGHTS = ['demand' => 30, 'trend' => 25, 'competition' => 20, 'price' => 10, 'fit' => 15];

    // ---- settings (stores.settings['radar']) ----

    public static function serpApiKey(Store $store): ?string
    {
        $enc = $store->settings['radar']['serpapi_key'] ?? null;

        return $enc ? rescue(fn () => Crypt::decryptString($enc), null, false) : (config('services.serpapi.key') ?: null);
    }

    public static function saveKey(Store $store, ?string $key): void
    {
        $settings = $store->settings ?? [];
        $settings['radar']['serpapi_key'] = $key ? Crypt::encryptString($key) : null;
        $store->settings = $settings;
        $store->save();
    }

    /**
     * Country, language and currency of a market (0 = the default site: worldwide trends, US shopping).
     *
     * @return array{id: int, name: string, country: ?string, language: string, currency: string}
     */
    public static function market(Store $store, int $marketId): array
    {
        $m = $marketId ? Market::find($marketId) : null;

        return $m ? ['id' => $m->id, 'name' => $m->name, 'country' => strtoupper((string) ((array) $m->countries)[0] ?? ''), 'language' => $m->default_locale, 'currency' => $m->currency]
            : ['id' => 0, 'name' => __('默认站点'), 'country' => null, 'language' => Localization::storeDefault($store), 'currency' => $store->baseCurrency()];
    }

    // ---- discovery ----

    /** Ideas from the store's own data in the last 30 days. Existing ideas keep their status; their numbers are refreshed. Returns how many are new. */
    public function discover(Store $store): int
    {
        $from = CarbonImmutable::now($store->timezone ?: 'UTC')->subDays(30)->toDateString();
        $new = 0;
        $terms = DB::table('search_terms')->where('store_id', $store->id)->where('date', '>=', $from)
            ->groupBy('market_id', 'term')->selectRaw('market_id, term, SUM(searches) as searches, SUM(zero_results) as zero_results')->get();
        foreach ($terms as $t) {
            $source = match (true) {
                $t->zero_results >= 2 => 'search_gap',        // asked for it, we don't have it
                $t->searches >= 5 => 'top_search',            // popular: is there room for more of it?
                default => null,
            };
            if ($source) {
                $new += $this->upsert((int) $t->market_id, $t->term, $source, ['site' => ['searches' => (int) $t->searches, 'zero_results' => (int) $t->zero_results]]);
            }
        }
        // Product types that test well: more of the same kind
        $hot = Product::where('test_status', 'hot')->whereNotNull('product_type')->where('product_type', '!=', '')
            ->selectRaw('product_type, COUNT(*) as n')->groupBy('product_type')->get();
        foreach ($hot as $h) {
            $new += $this->upsert(0, mb_strtolower($h->product_type), 'hot_type', ['site' => ['hot_products' => (int) $h->n]]);
        }

        return $new;
    }

    /** @return int 1 when created */
    public function upsert(int $marketId, string $keyword, string $source, array $signals = []): int
    {
        $keyword = Str::limit(trim(preg_replace('/\s+/u', ' ', mb_strtolower($keyword))), 120, '');
        if ($keyword === '') {
            return 0;
        }
        $o = ProductOpportunity::firstOrNew(['market_id' => $marketId, 'keyword' => $keyword]);
        $created = ! $o->exists;
        $o->source ??= $source;
        $o->signals = [...(array) $o->signals, ...$signals];
        $o->save();
        $this->rescore($o);

        return $created ? 1 : 0;
    }

    // ---- outside evidence ----

    public function enrich(Store $store, ProductOpportunity $o, SerpApi $api): ProductOpportunity
    {
        $m = self::market($store, $o->market_id);
        $signals = (array) $o->signals;
        $signals['trend'] = $api->trend($o->keyword, $m['country']);
        $signals['shopping'] = $api->shopping($o->keyword, $m['country'] ?? 'US', $m['language'], $m['currency']);
        $o->forceFill(['signals' => $signals, 'enriched_at' => now()])->save();

        return $this->rescore($o);
    }

    // ---- score ----

    public function rescore(ProductOpportunity $o): ProductOpportunity
    {
        $s = (array) $o->signals;
        $parts = [];

        // Demand: our own searches (log scale) or the search-trend level, whichever is stronger
        $site = (array) ($s['site'] ?? []);
        $searches = (int) ($site['searches'] ?? 0) + 3 * (int) ($site['hot_products'] ?? 0);
        $demand = [];
        if ($searches > 0) {
            $demand[] = min(1, log10(1 + $searches) / 2) + (($site['zero_results'] ?? 0) > 0 ? 0.15 : 0);
        }
        if (isset($s['trend']['average'])) {
            $demand[] = min(1, $s['trend']['average'] / 60);
        }
        // Imported from another tool (TikTok, Keepa…): volume on a log scale, 1M+ = full marks
        $ext = (array) ($s['external'] ?? []);
        if (! empty($ext['volume'])) {
            $demand[] = min(1, log10(1 + $ext['volume']) / 6);
        }
        if ($demand) {
            $parts['demand'] = min(1, max($demand));
        }
        // Trend: growth of the last 4 weeks vs the weeks before (−50% → 0, flat → 0.4, +100% → 1)
        $growth = $s['trend']['growth'] ?? $ext['growth'] ?? null;   // our own trend lookup wins
        if ($growth !== null) {
            $g = (float) $growth;
            $parts['trend'] = max(0, min(1, $g >= 0 ? 0.4 + $g / 100 * 0.6 : 0.4 + $g / 50 * 0.4));
        }
        // Competition: fewer sellers in shopping results = more room
        $sellers = $s['shopping']['sellers'] ?? $ext['sellers'] ?? null;
        if ($sellers !== null) {
            $parts['competition'] = max(0, 1 - $sellers / 40);
        }
        // Price: market price close to what the store already sells at = an easy fit for its customers
        if (! empty($s['shopping']['median']) && ! empty($s['shopping']['store_median'])) {
            $ratio = $s['shopping']['median'] / max(1, $s['shopping']['store_median']);
            $parts['price'] = max(0, 1 - abs(log($ratio)) / log(3));
        }
        if (isset($o->ai['fit_score'])) {
            $parts['fit'] = max(0, min(1, $o->ai['fit_score'] / 100));
        }

        $weight = array_sum(array_intersect_key(self::WEIGHTS, $parts));
        $total = $weight ? (int) round(array_sum(array_map(fn ($k) => $parts[$k] * self::WEIGHTS[$k], array_keys($parts))) / $weight * 100) : null;
        $o->forceFill(['scores' => array_map(fn ($v) => (int) round($v * 100), $parts) + ['evidence' => count($parts)], 'score' => $total])->save();

        return $o;
    }

    /** Where to look for it: supplier and marketplace searches (opened by the merchant; nothing is fetched). */
    public static function sourcingLinks(ProductOpportunity $o, array $market): array
    {
        $kw = rawurlencode($o->ai['source_keywords_en'] ?? $o->keyword);
        $zh = rawurlencode($o->ai['source_keywords_zh'] ?? $o->keyword);
        $gl = strtolower($market['country'] ?? 'us');

        return [
            ['name' => '1688', 'url' => "https://s.1688.com/selloffer/offer_search.htm?keywords={$zh}&charset=utf8"],
            ['name' => 'AliExpress', 'url' => "https://www.aliexpress.com/wholesale?SearchText={$kw}"],
            ['name' => 'Amazon', 'url' => "https://www.amazon.com/s?k={$kw}"],
            ['name' => 'Google Shopping', 'url' => 'https://www.google.com/search?tbm=shop&gl='.$gl.'&q='.rawurlencode($o->keyword)],
        ];
    }
}
