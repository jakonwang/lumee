<?php

namespace App\Markets;

use App\I18n\Localization;
use App\LeadFinder\LeadAi;
use App\LeadFinder\LeadSettings;
use App\Models\CollectedItem;
use App\Models\Market;
use App\Models\Product;
use App\Models\ProductMarketFit;
use App\Models\ProductOpportunity;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Market fit (市场适合度): how well a product suits each market, 0–100, with reasons and local tags.
 *
 * Evidence, each 0–1, weighted; missing evidence is left out and the rest re-weighted:
 *  - ai     Claude's judgement per market (optional, needs the store's Claude key)
 *  - trend  the product matches a product idea of that market in the research radar (local search
 *           trends, TikTok / Keepa imports, the store's own searches), weighted by the idea's score
 *  - sales  how the product does in that market: views, add-to-carts and orders over 30 days,
 *           compared with the market's best product (only once the market has some traffic)
 *  - rules  MarketRules: plugs, seasons, restricted goods, festivals
 * A "hard" rule (wrong plug, banned goods) caps the score at 15.
 *
 * Collected items keep their fit in collected_items.checks.markets (used by "publish where it fits");
 * products in product_market_fits (used by the storefront to show each country what suits it first).
 */
class MarketFit
{
    public const WEIGHTS = ['ai' => 35, 'trend' => 25, 'sales' => 30, 'rules' => 10];

    /** Default "sell where it fits" threshold. */
    public const DEFAULT_MIN = 50;

    private const HARD_CAP = 15;

    /** Traffic a market needs before its sales count as evidence. */
    private const MIN_MARKET_TRAFFIC = 50;

    /** @var array<int, Collection<int, ProductOpportunity>> */
    private array $ideas = [];

    private ?array $sales = null;

    // ---- facts ----

    /** What we know about a collected item: text to match, price (store currency, minor units). */
    public static function itemFacts(array $d): array
    {
        $prices = collect($d['variants'] ?? [])->pluck('price')->filter();

        return [
            'title' => (string) ($d['title'] ?? ''),
            'product_type' => (string) ($d['product_type'] ?? ''),
            'tags' => array_values((array) ($d['tags'] ?? [])),
            'description' => self::plain((string) ($d['description'] ?? '')),
            'price' => $prices->isEmpty() ? null : (int) $prices->min(),
        ];
    }

    public static function productFacts(Product $p): array
    {
        return [
            'title' => (string) $p->title,
            'product_type' => (string) $p->product_type,
            'tags' => array_values((array) $p->tags),
            'description' => self::plain((string) $p->description),
            'price' => ($min = $p->relationLoaded('variants') ? $p->variants->min('price') : $p->variants()->min('price')) !== null ? (int) $min : null,
        ];
    }

    private static function plain(string $html): string
    {
        return Str::limit(trim(preg_replace('/\s+/u', ' ', strip_tags(str_replace(['<br>', '</p>', '</li>'], ' ', $html)))), 2000, '');
    }

    private static function text(array $f): string
    {
        return mb_strtolower(implode(' ', [$f['title'], $f['product_type'], implode(' ', $f['tags']), $f['description']]));
    }

    // ---- scoring ----

    /**
     * @param  array<int, array{score: int, reason: string, tags: string[]}>  $ai  per market id
     * @return array<int, array{score: ?int, parts: array, reasons: string[], tags: string[], hard: bool}>  per market id
     */
    public function evaluate(Store $store, array $facts, ?Collection $markets = null, array $ai = [], ?int $productId = null): array
    {
        $markets ??= self::markets($store);
        $text = self::text($facts);
        $today = CarbonImmutable::now($store->timezone ?: 'UTC');
        $out = [];

        foreach ($markets as $m) {
            $parts = [];
            $reasons = [];
            $tags = [];

            if (isset($ai[$m->id]['score'])) {
                $parts['ai'] = max(0, min(100, (int) $ai[$m->id]['score'])) / 100;
                if (($r = trim((string) ($ai[$m->id]['reason'] ?? ''))) !== '') {
                    $reasons[] = 'AI：'.$r;
                }
                $tags = array_merge($tags, (array) ($ai[$m->id]['tags'] ?? []));
            }

            // Local demand: the best matching idea of this market's research radar
            $marketPrice = $this->marketPrice($m, $facts['price']);
            $rules = MarketRules::check($text, (array) $m->countries, $today);
            [$trend, $idea] = $this->trend($m, $text);
            if ($idea) {
                $parts['trend'] = $trend;
                $reasons[] = "当地需求：「{$idea->keyword}」（机会分 {$idea->score}）";
                $tags[] = $idea->keyword;
                $band = $idea->ai['price_band'] ?? null;
                if ($marketPrice && isset($band['min'], $band['max']) && $band['max'] > 0) {
                    $p = $marketPrice / 100;
                    if ($p >= $band['min'] * 0.8 && $p <= $band['max'] * 1.2) {
                        $rules[] = ['delta' => 0.15, 'reason' => "价格在当地常见区间 {$band['min']}–{$band['max']} {$m->currency}", 'hard' => false];
                    } elseif ($p > $band['max'] * 1.5) {
                        $rules[] = ['delta' => -0.2, 'reason' => "价格高于当地常见区间（{$band['min']}–{$band['max']} {$m->currency}）", 'hard' => false];
                    }
                }
            }

            if ($productId !== null && ($s = $this->salesPart($store, $productId, $m->id)) !== null) {
                $parts['sales'] = $s['part'];
                $reasons[] = $s['reason'];
            }

            $hard = false;
            if ($rules) {
                $parts['rules'] = max(0, min(1, 0.5 + array_sum(array_column($rules, 'delta'))));
                foreach ($rules as $r) {
                    $reasons[] = ($r['delta'] >= 0 ? '+ ' : '− ').$r['reason'];
                    $hard = $hard || $r['hard'];
                }
            }

            $weight = array_sum(array_intersect_key(self::WEIGHTS, $parts));
            $score = $weight ? (int) round(array_sum(array_map(fn ($k) => $parts[$k] * self::WEIGHTS[$k], array_keys($parts))) / $weight * 100) : null;
            if ($hard) {
                $score = min($score ?? self::HARD_CAP, self::HARD_CAP);
            }

            $out[$m->id] = [
                'score' => $score,
                'parts' => array_map(fn ($v) => (int) round($v * 100), $parts),
                'reasons' => array_values(array_unique($reasons)),
                'tags' => array_values(array_slice(array_unique(array_filter(array_map(fn ($t) => mb_strtolower(trim((string) $t)), $tags))), 0, 8)),
                'hard' => $hard,
            ];
        }

        return $out;
    }

    /** Store price (minor units) → what shoppers in the market pay; null without a price or an exchange rate. */
    private function marketPrice(Market $m, ?int $price): ?int
    {
        if (! $price || $m->rate() === null) {
            return null;
        }
        $ctx = app(MarketContext::class);

        return $ctx->within($m, fn () => $ctx->convertPrice($price));
    }

    /** @return array{0: float, 1: ?ProductOpportunity} */
    private function trend(Market $m, string $text): array
    {
        $ideas = $this->ideas[$m->id] ??= ProductOpportunity::where('market_id', $m->id)->whereNotNull('score')
            ->where('status', '!=', 'dropped')->orderByDesc('score')->limit(300)->get();
        $best = [0.0, null];
        foreach ($ideas as $o) {
            $cover = max([self::covers($o->keyword, $text), ...array_map(fn ($k) => self::covers((string) $k, $text), (array) ($o->ai['local_keywords'] ?? []))]);
            $v = $cover * $o->score / 100;
            if ($cover > 0 && $v > $best[0]) {
                $best = [$v, $o];
            }
        }

        return $best;
    }

    /** 1 = every word of the keyword is in the text, 0.6 = most of them, else 0. Chinese / Japanese keywords match as a whole. */
    public static function covers(string $keyword, string $text): float
    {
        $keyword = mb_strtolower(trim($keyword));
        if ($keyword === '') {
            return 0;
        }
        if (preg_match('/\p{Han}|\p{Hiragana}|\p{Katakana}/u', $keyword)) {
            return str_contains($text, $keyword) ? 1 : 0;
        }
        $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $keyword), fn ($w) => mb_strlen($w) >= 2));
        if (! $words) {
            return 0;
        }
        $hit = count(array_filter($words, fn ($w) => preg_match('/(?<![\p{L}\p{N}])'.self::plural($w).'(?![\p{L}\p{N}])/u', $text)));
        if ($hit === count($words)) {
            return 1;
        }

        return count($words) >= 3 && $hit / count($words) >= 0.66 ? 0.6 : 0;
    }

    /** A word and its English plural: scarf → scarf|scarves, bag → bags, accessory → accessories. */
    private static function plural(string $w): string
    {
        $q = preg_quote($w, '/');
        if (preg_match('/^(.+?)(fe|f)$/u', $w, $m)) {
            return '(?:'.$q.'s?|'.preg_quote($m[1], '/').'ves)';
        }
        if (preg_match('/^(.+[^aeiou])y$/u', $w, $m)) {
            return '(?:'.$q.'|'.preg_quote($m[1], '/').'ies)';
        }

        return $q.'(?:e?s)?';
    }

    /** Views, carts and orders in a market over 30 days vs the market's best product. */
    private function salesPart(Store $store, int $productId, int $marketId): ?array
    {
        if ($this->sales === null) {
            $from = now()->subDays(30);
            $eng = [];
            foreach (DB::table('product_market_stats')->where('store_id', $store->id)->where('date', '>=', $from->toDateString())->where('market_id', '>', 0)
                ->groupBy('product_id', 'market_id')->selectRaw('product_id, market_id, SUM(views) as v, SUM(carts) as c')->get() as $r) {
                $eng[$r->market_id][$r->product_id] = ['views' => (int) $r->v, 'carts' => (int) $r->c, 'orders' => 0];
            }
            foreach (DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.store_id', $store->id)->whereNotNull('orders.market_id')->whereNull('orders.cancelled_at')
                ->where('orders.created_at', '>=', $from)->whereNotNull('order_items.product_id')
                ->groupBy('order_items.product_id', 'orders.market_id')->selectRaw('order_items.product_id as p, orders.market_id as m, COUNT(DISTINCT orders.id) as n')->get() as $r) {
                $eng[$r->m][$r->p] ??= ['views' => 0, 'carts' => 0, 'orders' => 0];
                $eng[$r->m][$r->p]['orders'] = (int) $r->n;
            }
            $this->sales = $eng;
        }
        $market = $this->sales[$marketId] ?? [];
        if (array_sum(array_column($market, 'views')) < self::MIN_MARKET_TRAFFIC) {
            return null;   // too little traffic to say anything
        }
        $value = fn (array $s) => $s['views'] + 4 * $s['carts'] + 15 * $s['orders'];
        $max = max(array_map($value, $market));
        $s = $market[$productId] ?? ['views' => 0, 'carts' => 0, 'orders' => 0];

        return [
            'part' => $max > 0 ? log(1 + $value($s)) / log(1 + $max) : 0,
            'reason' => "近 30 天：浏览 {$s['views']}，加购 {$s['carts']}，订单 {$s['orders']}",
        ];
    }

    // ---- AI ----

    public static function aiReady(Store $store): bool
    {
        return LeadSettings::anthropicKey($store) !== null;
    }

    /**
     * Claude judges the product for every market at once.
     *
     * @return array<int, array{score: int, reason: string, tags: string[]}>  per market id
     */
    public function judge(Store $store, array $facts, ?Collection $markets = null): array
    {
        $markets ??= self::markets($store);
        if ($markets->isEmpty()) {
            return [];
        }
        $today = CarbonImmutable::now($store->timezone ?: 'UTC');
        $list = $markets->map(fn (Market $m) => [
            'market_id' => $m->id, 'name' => $m->name, 'countries' => (array) $m->countries,
            'language' => Localization::languages()[$m->default_locale] ?? $m->default_locale, 'currency' => $m->currency,
            'price' => ($p = $this->marketPrice($m, $facts['price'])) ? $p / 100 : null,
            'upcoming_events' => array_map(fn ($e) => $e['name'].' '.$e['start'], array_slice(ShoppingCalendar::upcoming((array) $m->countries, $today->startOfDay(), 90), 0, 4)),
        ])->values()->all();
        $product = json_encode(['title' => $facts['title'], 'product_type' => $facts['product_type'], 'tags' => $facts['tags'],
            'description' => Str::limit($facts['description'], 1500, '')], JSON_UNESCAPED_UNICODE);
        $marketsJson = json_encode($list, JSON_UNESCAPED_UNICODE);
        $kit = \App\Content\BrandKit::for($store)->all();

        $prompt = <<<TXT
        You are a cross-border e-commerce merchandiser for the online store "{$store->name}" (positioning: {$kit['positioning']}).
        Today is {$today->toDateString()}. For EACH market below, judge how well this product would sell there right now.

        Think about: local demand and taste, climate and season, culture and religion, local sizes / plugs / standards,
        the price in local currency compared with what people there usually pay, upcoming shopping events, and whether
        it may be restricted or need certification there.

        For each market return:
        - market_id: as given
        - score: 0–100 (80+ = a strong seller there, 50 = average, below 30 = don't sell it there)
        - reason: one short sentence in Simplified Chinese (max 40 characters), the main reason
        - tags: 2–5 short lowercase tags in the market's language that a local shopper would use (style, occasion, season, audience)

        Be honest and specific; do not give every market the same score.

        Product (JSON): {$product}
        Markets (JSON): {$marketsJson}
        TXT;

        $schema = [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['markets'],
            'properties' => ['markets' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['market_id', 'score', 'reason', 'tags'],
                'properties' => ['market_id' => ['type' => 'integer'], 'score' => ['type' => 'integer'], 'reason' => ['type' => 'string'],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string']]],
            ]]],
        ];
        $r = app(LeadAi::class)->json($store, $prompt, $schema, 'low', 3000);

        return self::parseJudgement($r, $markets);
    }

    /** Keep answers for known markets only, clamp scores, trim reasons and tags. */
    public static function parseJudgement(array $r, Collection $markets): array
    {
        $ids = $markets->pluck('id')->flip();
        $out = [];
        foreach ((array) ($r['markets'] ?? []) as $row) {
            $id = (int) ($row['market_id'] ?? 0);
            if (! $ids->has($id) || ! isset($row['score'])) {
                continue;
            }
            $out[$id] = [
                'score' => max(0, min(100, (int) $row['score'])),
                'reason' => Str::limit(trim((string) ($row['reason'] ?? '')), 80, ''),
                'tags' => array_values(array_slice(array_filter(array_map(fn ($t) => Str::limit(mb_strtolower(trim((string) $t)), 40, ''), (array) ($row['tags'] ?? []))), 0, 5)),
            ];
        }

        return $out;
    }

    // ---- collected items ----

    /** Score a collected item (optionally asking the AI first) and keep it in checks.markets. */
    public function scoreItem(Store $store, CollectedItem $item, bool $withAi = false): CollectedItem
    {
        $markets = self::markets($store);
        if ($markets->isEmpty()) {
            return $item;
        }
        $facts = self::itemFacts($item->data ?? []);
        $checks = $item->checks ?? [];
        $ai = $withAi ? $this->judge($store, $facts, $markets) : (array) ($checks['markets_ai'] ?? []);
        $fits = $this->evaluate($store, $facts, $markets, $ai);
        $checks['markets'] = $fits;
        if ($withAi) {
            $checks['markets_ai'] = $ai;
            $checks['markets_ai_at'] = now()->toIso8601String();
        }
        $item->forceFill(['checks' => $checks])->save();

        return $item;
    }

    /**
     * Markets to sell in when publishing "where it fits": every market except those scored below $min.
     * Markets with no evidence stay on (nothing says it doesn't fit).
     *
     * @return int[]
     */
    public static function marketsToSell(array $fits, Collection $markets, int $min): array
    {
        return $markets->filter(fn (Market $m) => ($fits[$m->id]['score'] ?? null) === null || $fits[$m->id]['score'] >= $min)
            ->pluck('id')->values()->all();
    }

    // ---- products ----

    /** Score one product for every market (AI judgement reused unless $withAi asks for a new one). */
    public function scoreProduct(Store $store, Product $product, bool $withAi = false, ?array $ai = null): void
    {
        $markets = self::markets($store);
        if ($markets->isEmpty()) {
            return;
        }
        $existing = ProductMarketFit::where('product_id', $product->id)->get()->keyBy('market_id');
        $facts = self::productFacts($product);
        if ($withAi) {
            $ai = $this->judge($store, $facts, $markets);
        }
        $ai ??= $existing->mapWithKeys(fn ($f) => [$f->market_id => $f->ai])->filter()->all();
        foreach ($this->evaluate($store, $facts, $markets, $ai, $product->id) as $marketId => $fit) {
            $row = $existing->get($marketId) ?? new ProductMarketFit(['product_id' => $product->id, 'market_id' => $marketId]);
            $row->fill(['score' => $fit['score'], 'parts' => $fit['parts'], 'reasons' => $fit['reasons'], 'tags' => $fit['tags']]);
            if (isset($ai[$marketId]) && ($withAi || ! $row->ai)) {
                $row->fill(['ai' => $ai[$marketId], 'ai_at' => $row->ai_at && ! $withAi ? $row->ai_at : now()]);
            }
            $row->save();
        }
    }

    /** Re-score every product of the store (daily: sales and trends change). Returns how many. */
    public function scoreAll(Store $store): int
    {
        if (self::markets($store)->isEmpty()) {
            return 0;
        }
        $n = 0;
        Product::with('variants:id,product_id,price')->where('status', '!=', 'archived')->chunkById(200, function ($products) use ($store, &$n) {
            foreach ($products as $p) {
                $this->scoreProduct($store, $p);
                $n++;
            }
        });

        return $n;
    }

    /** @return Collection<int, Market> */
    public static function markets(Store $store): Collection
    {
        return Market::orderBy('position')->orderBy('id')->get()->each(fn (Market $m) => $m->setRelation('store', $store));
    }

    /** Fit rows of a product for the admin, by market id. */
    public static function forProduct(int $productId): array
    {
        return ProductMarketFit::where('product_id', $productId)->get()
            ->mapWithKeys(fn ($f) => [$f->market_id => ['score' => $f->score, 'parts' => $f->parts ?? [], 'reasons' => $f->reasons ?? [], 'tags' => $f->tags ?? [], 'ai_at' => $f->ai_at?->toIso8601String()]])->all();
    }
}
