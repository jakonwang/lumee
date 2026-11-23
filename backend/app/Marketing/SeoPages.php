<?php

namespace App\Marketing;

use App\I18n\Localization;
use App\LeadFinder\LeadAi;
use App\Markets\MarketFit;
use App\Models\Market;
use App\Models\Product;
use App\Models\ProductMarketFit;
use App\Models\ProductOpportunity;
use App\Models\SeoPage;
use App\Models\Store;
use App\Themes\SectionData;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * SEO landing pages (SEO 落地页): a page per market and keyword people search there, in the market's
 * language, with the store's matching products (best market fit first), a short local-language intro
 * and FAQ. Built from research-radar ideas (local search demand), so each country gets pages for what
 * its shoppers actually search. Claude writes the copy when the store has a key; otherwise a plain template.
 *
 * A page with fewer than MIN_PRODUCTS matching products stays a draft: thin pages hurt search ranking.
 */
class SeoPages
{
    public const MIN_PRODUCTS = 2;

    public const MAX_PRODUCTS = 24;

    /** Keyword → page, for a market (null = the default site). Existing pages for the same market + keyword are refreshed, not duplicated. */
    public function generate(Store $store, ?Market $market, string $keyword, bool $withAi, ?ProductOpportunity $idea = null): SeoPage
    {
        $keyword = Str::limit(trim(preg_replace('/\s+/u', ' ', mb_strtolower($keyword))), 120, '');
        $locale = $market?->default_locale ?? Localization::storeDefault($store);
        $page = SeoPage::where('market_id', $market?->id)->where('keyword', $keyword)->first()
            ?? new SeoPage(['market_id' => $market?->id, 'keyword' => $keyword, 'slug' => $this->slug($keyword, $market), 'status' => 'draft']);
        $products = $this->products($page->fill(['locale' => $locale]), $idea);

        $copy = null;
        if ($withAi && LeadAi::ready($store)) {
            $copy = $this->write($store, $market, $locale, $keyword, $products, $idea);
        }
        $copy ??= self::template($store, $market, $locale, $keyword);
        $page->fill([
            'title' => Str::limit($copy['title'], 160, ''), 'meta_description' => Str::limit($copy['meta_description'], 300, ''),
            'heading' => Str::limit($copy['heading'], 160, ''), 'intro' => self::clean($copy['intro']),
            'faq' => array_slice(array_values(array_filter($copy['faq'], fn ($f) => trim($f['q'] ?? '') !== '' && trim($f['a'] ?? '') !== '')), 0, 6),
            'ai' => $copy['ai'] ?? false, 'opportunity_id' => $idea?->id ?? $page->opportunity_id,
        ])->save();

        return $page;
    }

    /**
     * The products the page shows: pinned ones in order, else those whose title, type or tags match the
     * keyword (or the idea's local keywords), the best fit for the page's market first.
     *
     * @return Collection<int, Product>
     */
    public function products(SeoPage $page, ?ProductOpportunity $idea = null): Collection
    {
        if ($page->product_ids) {
            $ids = array_map('intval', $page->product_ids);

            return Product::active()->with(SectionData::VARIANTS)->whereIn('id', $ids)->get()->sortBy(fn ($p) => array_search($p->id, $ids))->values();
        }
        $idea ??= $page->opportunity_id ? ProductOpportunity::find($page->opportunity_id) : null;
        $keywords = array_values(array_unique(array_filter([$page->keyword, ...(array) ($idea?->ai['local_keywords'] ?? [])])));
        $fits = $page->market_id ? ProductMarketFit::where('market_id', $page->market_id)->pluck('score', 'product_id') : collect();
        $scored = [];
        foreach (Product::active()->with(SectionData::VARIANTS)->latest('id')->limit(2000)->get() as $p) {
            $text = mb_strtolower(implode(' ', [$p->title, $p->product_type, implode(' ', (array) $p->tags)]));
            $cover = max(array_map(fn ($k) => MarketFit::covers($k, $text), $keywords) ?: [0]);
            if ($cover > 0) {
                $scored[] = [$p, $cover * 100 + (int) ($fits->get($p->id) ?? 50)];
            }
        }
        usort($scored, fn ($a, $b) => $b[1] <=> $a[1]);

        return collect(array_slice(array_column($scored, 0), 0, self::MAX_PRODUCTS));
    }

    public function publish(SeoPage $page): ?string
    {
        if ($this->products($page)->count() < self::MIN_PRODUCTS) {
            return __('只有 :n 个匹配的商品，至少需要 :min 个才能发布（内容太少的页面会影响搜索排名）', ['n' => $this->products($page)->count(), 'min' => self::MIN_PRODUCTS]);
        }
        $page->forceFill(['status' => 'published', 'published_at' => $page->published_at ?? now()])->save();

        return null;
    }

    /** Full URL of a page on its site: "https://shop…/es-mx/s/fundas-de-silicona-mx". */
    public static function url(Store $store, SeoPage $page): string
    {
        $market = $page->market_id ? Market::find($page->market_id) : null;
        $prefix = $market ? $market->prefix($page->locale) : ($page->locale === Localization::storeDefault($store) ? '' : '/'.$page->locale);

        return $store->storefrontUrl().$prefix.'/s/'.rawurlencode($page->slug);
    }

    private function slug(string $keyword, ?Market $market): string
    {
        $base = Str::limit(Str::slug($keyword) ?: 'page', 110, '').($market ? '-'.$market->handle : '');
        $slug = $base;
        for ($n = 2; SeoPage::where('slug', $slug)->exists(); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }

    /** Only simple formatting survives: p, ul, ol, li, strong, em, h2, h3 — no attributes, links or scripts. */
    public static function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }
        $html = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', '', $html);
        $html = strip_tags($html, '<p><ul><ol><li><strong><em><h2><h3><br>');

        return trim(preg_replace('#<(\w+)\s[^>]*>#', '<$1>', $html));
    }

    /** @return array{title: string, meta_description: string, heading: string, intro: string, faq: array, ai: bool} */
    private function write(Store $store, ?Market $market, string $locale, string $keyword, Collection $products, ?ProductOpportunity $idea): ?array
    {
        $language = Localization::languages()[$locale] ?? $locale;
        $where = $market ? $market->name.' ('.implode(', ', (array) $market->countries).')' : 'worldwide';
        $kit = \App\Content\BrandKit::for($store)->all();
        $items = $products->take(12)->map(fn ($p) => $p->title)->implode('; ');
        $hints = $idea?->ai ? json_encode(array_intersect_key($idea->ai, array_flip(['summary', 'local_keywords', 'season', 'price_band'])), JSON_UNESCAPED_UNICODE) : '';
        $prompt = <<<TXT
        Write an SEO landing page for the online store "{$store->name}" (positioning: {$kit['positioning']}).
        Target search keyword: "{$keyword}". Shoppers in: {$where}. Write everything in {$language}, for local shoppers, naturally (not keyword-stuffed).
        Products on the page: {$items}
        Market notes (may be empty): {$hints}

        Return:
        - title: the HTML title, 50–60 characters, starting with the keyword, ending with " | {$store->name}"
        - meta_description: 140–155 characters, what the shopper finds here and why buy here
        - heading: the H1, short, contains the keyword
        - intro: 2 short paragraphs of HTML (<p> only, <strong> allowed) about choosing these products, useful to a shopper; no invented facts, prices, certifications or shipping promises
        - faq: 3–4 questions local shoppers ask about this kind of product, each answered in 1–2 sentences, general advice only (no claims about this store's policies)
        TXT;
        $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['title', 'meta_description', 'heading', 'intro', 'faq'], 'properties' => [
            'title' => ['type' => 'string'], 'meta_description' => ['type' => 'string'], 'heading' => ['type' => 'string'], 'intro' => ['type' => 'string'],
            'faq' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['q', 'a'],
                'properties' => ['q' => ['type' => 'string'], 'a' => ['type' => 'string']]]],
        ]];
        try {
            $r = app(LeadAi::class)->json($store, $prompt, $schema, 'low', 3000);
        } catch (\RuntimeException $e) {
            report($e);

            return null;   // the template still makes a usable page
        }

        return ['title' => (string) $r['title'], 'meta_description' => (string) $r['meta_description'], 'heading' => (string) $r['heading'],
            'intro' => (string) $r['intro'], 'faq' => (array) $r['faq'], 'ai' => true];
    }

    /** No AI: a short, honest page in the market's language (English for languages we have no template for). */
    public static function template(Store $store, ?Market $market, string $locale, string $keyword): array
    {
        $k = Str::ucfirst($keyword);
        $t = [
            'en' => ['%s | %s', 'Shop %s at %s: our current selection, with prices in your currency.', '<p>Browse our selection of %s, chosen for shoppers in %s.</p>', 'your country'],
            'es' => ['%s | %s', 'Compra %s en %s: nuestra selección actual, con precios en tu moneda.', '<p>Descubre nuestra selección de %s, elegida para compradores de %s.</p>', 'tu país'],
            'pt' => ['%s | %s', 'Compre %s na %s: nossa seleção atual, com preços na sua moeda.', '<p>Veja nossa seleção de %s, escolhida para compradores de %s.</p>', 'seu país'],
            'fr' => ['%s | %s', 'Achetez %s chez %s : notre sélection actuelle, avec les prix dans votre devise.', '<p>Découvrez notre sélection de %s, choisie pour les acheteurs de %s.</p>', 'votre pays'],
            'de' => ['%s | %s', '%s bei %s kaufen: unsere aktuelle Auswahl, mit Preisen in Ihrer Währung.', '<p>Entdecken Sie unsere Auswahl an %s, ausgewählt für Kunden in %s.</p>', 'Ihrem Land'],
            'it' => ['%s | %s', 'Acquista %s su %s: la nostra selezione attuale, con prezzi nella tua valuta.', '<p>Scopri la nostra selezione di %s, scelta per gli acquirenti in %s.</p>', 'il tuo paese'],
        ][$locale] ?? null;
        $t ??= ['%s | %s', 'Shop %s at %s: our current selection, with prices in your currency.', '<p>Browse our selection of %s, chosen for shoppers in %s.</p>', 'your country'];

        return [
            'title' => sprintf($t[0], $k, $store->name),
            'meta_description' => sprintf($t[1], $keyword, $store->name),
            'heading' => $k,
            'intro' => sprintf($t[2], e($keyword), e($market?->name ?? $t[3])),
            'faq' => [],
            'ai' => false,
        ];
    }
}
