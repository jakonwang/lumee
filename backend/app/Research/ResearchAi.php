<?php

namespace App\Research;

use App\Content\BrandKit;
use App\I18n\Localization;
use App\LeadFinder\LeadAi;
use App\Models\Product;
use App\Models\ProductOpportunity;
use App\Models\Store;

/**
 * Claude's market analysis of a product idea: does it fit this store and this country, at what
 * price, what to call it locally, what to watch out for, and what to search for at suppliers.
 * It reasons over the evidence we have (and says so when evidence is thin) — it does not browse.
 */
class ResearchAi
{
    public function analyze(Store $store, ProductOpportunity $o): array
    {
        $m = Radar::market($store, $o->market_id);
        $k = BrandKit::for($store)->all();
        $country = $m['country'] ?? 'worldwide (no single country)';
        $language = Localization::languages()[$m['language']] ?? $m['language'];
        $types = Product::where('status', 'active')->whereNotNull('product_type')->distinct()->limit(30)->pluck('product_type')->implode(', ');
        $evidence = json_encode(array_intersect_key((array) $o->signals, array_flip(['site', 'trend', 'shopping'])), JSON_UNESCAPED_UNICODE);

        $prompt = <<<TXT
        You are a cross-border e-commerce product researcher for the online store "{$store->name}".
        Store positioning: {$k['positioning']}
        Audience: {$k['audience']}
        What the store already sells (product types): {$types}

        Product idea: "{$o->keyword}"
        Target market: {$m['name']} — country {$country}, language {$language}, currency {$m['currency']}.

        Evidence (JSON; "site" = our own store's searches/sales, "trend" = Google Trends interest 0–100 and growth %, "shopping" = Google Shopping sellers and prices in minor units):
        {$evidence}

        Give a practical verdict for this market. Base it on the evidence and on what you know about this country's buyers, seasons and rules; say plainly when the evidence is thin.
        - fit_score 0–100: how well this idea fits this store AND this market.
        - summary: 2–3 sentences, in Chinese (the merchant reads Chinese).
        - price_band: a sensible retail price range in {$m['currency']} (major units).
        - local_keywords: 3–6 search terms shoppers in this market actually use, in {$language}.
        - title_ideas: 2 product title ideas in {$language}.
        - risks: compliance, safety, IP/brand, shipping (batteries, liquids, size) or cultural issues, in Chinese. Empty if none.
        - season: when demand peaks in this market, in Chinese ("" if not seasonal).
        - source_keywords_zh: a Chinese search phrase for 1688. source_keywords_en: an English one for AliExpress.
        TXT;

        $schema = ['type' => 'object', 'additionalProperties' => false,
            'required' => ['fit_score', 'summary', 'price_band', 'local_keywords', 'title_ideas', 'risks', 'season', 'source_keywords_zh', 'source_keywords_en'],
            'properties' => [
                'fit_score' => ['type' => 'integer'], 'summary' => ['type' => 'string'],
                'price_band' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['min', 'max'], 'properties' => ['min' => ['type' => 'number'], 'max' => ['type' => 'number']]],
                'local_keywords' => ['type' => 'array', 'items' => ['type' => 'string']], 'title_ideas' => ['type' => 'array', 'items' => ['type' => 'string']],
                'risks' => ['type' => 'array', 'items' => ['type' => 'string']], 'season' => ['type' => 'string'],
                'source_keywords_zh' => ['type' => 'string'], 'source_keywords_en' => ['type' => 'string'],
            ]];
        $r = app(LeadAi::class)->json($store, $prompt, $schema, 'medium', 3000);
        $r['fit_score'] = max(0, min(100, (int) $r['fit_score']));
        $r['currency'] = $m['currency'];

        return $r;
    }
}
