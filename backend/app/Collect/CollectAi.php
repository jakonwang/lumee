<?php

namespace App\Collect;

use App\I18n\Localization;
use App\LeadFinder\LeadAi;
use App\LeadFinder\LeadSettings;
use App\Models\Store;
use RuntimeException;

/**
 * Rewrites a collected product in the store's own voice and language with Claude (the store's
 * Claude key from the lead finder settings): a clean title, a structured description, a product
 * type, tags, translated option names/values, and risks a person should check.
 * Facts (materials, sizes, quantities) are kept; nothing is invented.
 */
class CollectAi
{
    public static function ready(Store $store): bool
    {
        return LeadSettings::anthropicKey($store) !== null;
    }

    public function rewrite(Store $store, array $d): array
    {
        $language = Localization::languages()[Localization::storeDefault($store)] ?? 'English';
        $source = json_encode([
            'title' => $d['title'],
            'description' => mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags(str_replace(['<br>', '</p>', '</li>'], "\n", $d['description'])))), 0, 4000),
            'product_type' => $d['product_type'] ?? null,
            'tags' => $d['tags'] ?? [],
            'options' => $d['options'] ?? [],
        ], JSON_UNESCAPED_UNICODE);

        // The brand kit (内容工坊) sets the voice, title rules and words we never use
        $kit = \App\Content\BrandKit::for($store);
        $k = $kit->all();
        $banned = implode(', ', $kit->bannedWords());
        $pattern = $k['title_pattern'] !== '' ? " Follow this title pattern when it fits: {$k['title_pattern']}." : '';

        $prompt = <<<TXT
        You are the merchandiser of the online store "{$store->name}". A product was collected from a supplier or another store.
        Rewrite it so it reads like our own brand's product, in {$language}.
        Brand positioning: {$k['positioning']}
        Voice: {$k['voice']}

        Rules:
        - Title: what it is + the key attribute (material, style, use). {$k['title_min']}–{$k['title_max']} characters.{$pattern} No supplier names, no brand names we don't own, none of these words: {$banned}. No ALL CAPS, no emoji.
        - Description: HTML using only <p>, <ul>, <li>, <strong>. A short opening paragraph, then 3–6 bullet points with the concrete facts from the source (material, size, what's included, care). Keep every number and fact from the source; never invent specifications, certifications or claims (e.g. "hypoallergenic", "waterproof") that the source doesn't state.
        - product_type: a short category in {$language}, e.g. "Necklace".
        - tags: 3–8 lowercase search keywords in {$language}.
        - options: the same options in the same order with the same number of values, translated to {$language} (keep sizes like "S", "XL", "16in" as they are).
        - risks: anything a person must check before selling: brand or character names (possible counterfeit), medical or safety claims, missing information. Empty list if none.

        Source product (JSON):
        {$source}
        TXT;

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['title', 'description_html', 'product_type', 'tags', 'options', 'risks'],
            'properties' => [
                'title' => ['type' => 'string'],
                'description_html' => ['type' => 'string'],
                'product_type' => ['type' => 'string'],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                'options' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['name', 'values'],
                    'properties' => ['name' => ['type' => 'string'], 'values' => ['type' => 'array', 'items' => ['type' => 'string']]]]],
                'risks' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];

        $r = app(LeadAi::class)->json($store, $prompt, $schema, 'medium', 4000);

        return $this->apply($d, $r);
    }

    /** Merge the AI answer in; options are only replaced when the shape matches exactly (variants keep pointing at them). */
    public function apply(array $d, array $r): array
    {
        if (trim((string) ($r['title'] ?? '')) === '') {
            throw new RuntimeException('AI 没有返回标题，请重试');
        }
        $d['title'] = trim($r['title']);
        $d['description'] = (string) ($r['description_html'] ?? $d['description']);
        $d['product_type'] = trim((string) ($r['product_type'] ?? '')) ?: ($d['product_type'] ?? null);
        $d['tags'] = array_slice(array_values(array_filter(array_map('trim', (array) ($r['tags'] ?? [])))), 0, 10);

        $old = $d['options'] ?? [];
        $new = (array) ($r['options'] ?? []);
        $same = count($old) === count($new) && collect($old)->every(fn ($o, $i) => count($o['values']) === count((array) ($new[$i]['values'] ?? [])));
        if ($old && $same) {
            foreach ($d['variants'] as $vi => $v) {
                $opts = [];
                foreach ($old as $i => $o) {
                    $pos = array_search($v['options'][$o['name']] ?? null, $o['values'], true);
                    $opts[trim($new[$i]['name'])] = trim($new[$i]['values'][$pos === false ? 0 : $pos]);
                }
                $d['variants'][$vi]['options'] = $opts;
            }
            $d['options'] = array_map(fn ($o) => ['name' => trim($o['name']), 'values' => array_map('trim', $o['values'])], $new);
        }

        return Item::normalize($d) + ['ai_risks' => array_values(array_filter((array) ($r['risks'] ?? [])))];
    }
}
