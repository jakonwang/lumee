<?php

namespace App\Content;

use App\I18n\Localization;
use App\LeadFinder\LeadAi;
use App\Models\Product;
use App\Models\Store;
use App\Models\Translation;

/**
 * Rewrites a product's title, description and SEO text in one language following the brand kit
 * (voice, title pattern and length, detail-page sections, banned words). Returns a suggestion;
 * nothing is saved until the merchant accepts it (ContentVersions::apply).
 */
class ContentAi
{
    private const SECTION_NAMES = [
        'highlights' => 'Highlights (3–5 short benefit bullets)', 'details' => 'Details (what it is, how it is made)',
        'materials' => 'Materials', 'size' => 'Size & fit / dimensions', 'care' => 'Care instructions',
        'shipping' => 'Shipping & returns (only general wording, no promises the source does not make)', 'faq' => 'FAQ (2–3 questions)',
    ];

    /** @return array{title: string, description: string, seo_title: string, seo_description: string, notes: string[]} */
    public function suggest(Store $store, Product $product, string $locale): array
    {
        $k = BrandKit::for($store)->all();
        $language = Localization::languages()[$locale] ?? $locale;
        $current = ContentVersions::current($product, $locale === Localization::storeDefault($store) ? null : $locale);
        // Translating: start from the default-language text
        $source = $current['title'] ? $current : ContentVersions::current($product, null);
        $sections = implode("\n", array_map(fn ($s) => '  - '.self::SECTION_NAMES[$s], $k['sections']));
        $facts = json_encode([
            'title' => $source['title'],
            'description' => mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags(str_replace(['<br>', '</p>', '</li>'], "\n", (string) $source['description'])))), 0, 5000),
            'product_type' => $product->getRawOriginal('product_type'),
            'tags' => $product->tags,
            'options' => $product->options()->get(['name', 'values'])->map->only('name', 'values'),
        ], JSON_UNESCAPED_UNICODE);
        $banned = implode(', ', BrandKit::for($store)->bannedWords());

        $prompt = <<<TXT
        You write product copy for the online store "{$store->name}".
        Brand positioning: {$k['positioning']}
        Audience: {$k['audience']}
        Voice: {$k['voice']}

        Rewrite this product's copy in {$language}.
        - Title: {$k['title_min']}–{$k['title_max']} characters. Pattern to follow if it fits: {$k['title_pattern']}. No words from this list: {$banned}. No ALL CAPS, no emoji, no brand names we don't own.
        - Description: HTML with only <h3>, <p>, <ul>, <li>, <strong>. Use these sections, in this order, with an <h3> heading each (skip a section only when the facts give nothing for it):
        {$sections}
        - Keep every fact and number from the source. Never invent materials, certifications, measurements or claims (e.g. "hypoallergenic", "waterproof") that the source doesn't state.
        - seo_title: up to 60 characters. seo_description: up to 155 characters, a reason to click.
        - notes: things the merchant should check or add (missing facts, risky claims). Empty if none.

        Source (JSON):
        {$facts}
        TXT;

        $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['title', 'description_html', 'seo_title', 'seo_description', 'notes'],
            'properties' => ['title' => ['type' => 'string'], 'description_html' => ['type' => 'string'], 'seo_title' => ['type' => 'string'],
                'seo_description' => ['type' => 'string'], 'notes' => ['type' => 'array', 'items' => ['type' => 'string']]]];
        $r = app(LeadAi::class)->json($store, $prompt, $schema, 'medium', 5000);

        return [
            'title' => trim((string) $r['title']),
            'description' => self::safeHtml((string) $r['description_html']),
            'seo_title' => mb_substr(trim((string) $r['seo_title']), 0, 70),
            'seo_description' => mb_substr(trim((string) $r['seo_description']), 0, 320),
            'notes' => array_values(array_filter((array) $r['notes'])),
        ];
    }

    /** Only the formatting the storefront theme styles; no attributes. */
    public static function safeHtml(string $html): string
    {
        $html = strip_tags(preg_replace('#<(script|style)[^>]*>.*?</\1>#is', '', $html), '<h3><h4><p><ul><ol><li><strong><em><br>');

        return trim(preg_replace('#<(h3|h4|p|ul|ol|li|strong|em)\b[^>]*>#i', '<$1>', $html));
    }

    /** Is there a translation row for this product + language? (used by the editor to label the source) */
    public static function translated(Product $product, string $locale): bool
    {
        return Translation::where('type', 'product')->where('resource_id', $product->id)->where('locale', $locale)->exists();
    }
}
