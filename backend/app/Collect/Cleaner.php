<?php

namespace App\Collect;

use App\Models\Store;
use Illuminate\Support\Str;

/**
 * Rule-based cleanup of a collected product (always runs; AI rewriting is optional on top):
 * removes supplier noise from the title, unsafe markup from the description, icon/thumbnail images,
 * and flags what a person must look at: brand names, untranslated Chinese, missing images or prices.
 */
class Cleaner
{
    /** Supplier / marketplace noise that doesn't belong in a brand's product title. */
    private const NOISE = [
        '/\b20\d\d\s*new\b/i', '/^\s*new\s+(?=\p{L})/iu', '/\b(20\d\d\s*)?(new|hot)\s*(arrival|sale|style|fashion)s?\b/i', '/\bhot\s*selling\b/i', '/\bbest\s*sell(er|ing)\b/i',
        '/\bfree\s*shipping\b/i', '/\b(wholesale|dropshipping|drop\s*shipping|factory(\s*direct)?|in\s*stock|ready\s*to\s*ship|oem|odm)\b/i',
        '/\b(high|top|best|good)\s*quality\b/i', '/\b100\s*%\s*(original|authentic|genuine)\b/i', '/\bcheap(est)?\b/i',
        '/\b20\d\d\b/', '/(爆款|热卖|新款|包邮|厂家直销|一件代发|现货|批发|跨境专供|亚马逊|速卖通|外贸)/u',
    ];

    /** Brand / IP names that make a listing a counterfeit or infringement risk (checked as whole words). */
    private const BRANDS = [
        'nike', 'adidas', 'puma', 'gucci', 'louis vuitton', 'lv', 'chanel', 'hermes', 'hermès', 'prada', 'dior', 'fendi', 'balenciaga',
        'versace', 'burberry', 'cartier', 'tiffany', 'pandora', 'swarovski', 'rolex', 'iphone', 'airpods', 'samsung',
        'disney', 'marvel', 'pokemon', 'pokémon', 'hello kitty', 'sanrio', 'barbie', 'lego', 'nintendo', 'playstation', 'star wars',
        'harry potter', 'ray-ban', 'rayban', 'north face', 'supreme', 'yeezy', 'air jordan', 'off-white', 'ugg', 'crocs', 'dyson',
    ];

    /** @return array{0: array, 1: array<int, array{type: string, message: string}>} cleaned data + notes */
    public function clean(Store $store, array $d): array
    {
        $notes = [];
        $zh = str_starts_with((string) $store->locale, 'zh');

        // Title
        $title = (string) ($d['title'] ?? '');
        $title = preg_replace('/[【\[(（][^】\])）]{0,40}[】\])）]/u', ' ', $title);   // 【Hot sale】 [2026 New]
        $title = preg_replace(self::NOISE, ' ', $title);
        $title = preg_replace('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', '', $title);   // emoji
        $title = trim(preg_replace('/\s*[|,;\/+-]{1,}\s*$|^\s*[|,;\/+-]+\s*/', '', preg_replace('/\s{2,}/', ' ', $title)));
        $title = preg_replace('/\s*([|\/,])\s*(?=[|\/,])/', '', $title);
        if ($title !== '' && mb_strtoupper($title) === $title && preg_match('/[A-Z]{4,}/', $title)) {
            $title = Str::title(mb_strtolower($title));   // SHOUTING → Title Case
        }
        $d['title'] = Str::limit(trim($title, " \t-|,"), 120, '');

        // Description: plain formatting only (no scripts, styles, links to the supplier, inline handlers)
        $html = preg_replace('#<(script|style|iframe|noscript|form)[^>]*>.*?</\1>#is', '', (string) ($d['description'] ?? ''));
        $html = strip_tags($html, '<p><br><ul><ol><li><strong><b><em><i><h2><h3><h4><table><thead><tbody><tr><td><th><img>');
        $html = preg_replace_callback('#<(img)\b[^>]*>#i', fn ($m) => preg_match('#src=["\']([^"\']+)["\']#i', $m[0], $s) && Item::url($s[1]) ? '<img src="'.e(Item::url($s[1])).'" alt="">' : '', $html);
        $html = preg_replace('#<(p|ul|ol|li|strong|b|em|i|h2|h3|h4|table|thead|tbody|tr|td|th)\b[^>]*>#i', '<$1>', $html);
        $html = preg_replace(self::NOISE, '', $html);
        $d['description'] = trim(preg_replace('/(\s*<br>\s*){3,}/i', '<br><br>', $html));
        if (preg_match('#<img#i', $d['description'])) {
            $notes[] = ['type' => 'description_images', 'message' => '描述里有供应商的图片，建议换成自己的图片或文字'];
        }

        // Images: no icons, sprites or tiny thumbnails; ask for the full-size file
        $images = [];
        foreach ((array) ($d['images'] ?? []) as $u) {
            if (preg_match('#(sprite|icon|logo|avatar|placeholder|loading|blank)\b|\.(svg|gif)(\?|$)#i', $u)) {
                continue;
            }
            $u = preg_replace('#_(\d{2,4}x\d{2,4}|\d{2,3}x)(q\d+)?\.(jpe?g|png|webp)(_\.webp)?#i', '', $u);   // AliExpress/Taobao thumbnails
            $u = preg_replace('#_(\d{2,4}x\d{2,4}|small|thumb|compact)(\.(jpe?g|png|webp))#i', '$2', $u);     // Shopify size suffix
            $images[] = $u;
        }
        $d['images'] = array_slice(array_values(array_unique($images)), 0, Item::MAX_IMAGES);

        $d['tags'] = array_values(array_unique(array_map(fn ($t) => mb_strtolower($t), (array) ($d['tags'] ?? []))));
        $d['vendor'] = null;   // the supplier's brand is not ours

        // What needs a person
        if (! $zh && preg_match('/\p{Han}/u', $d['title'].' '.strip_tags($d['description']))) {
            $notes[] = ['type' => 'translate', 'message' => '标题或描述里有中文，需要翻译（可以用 AI 优化）'];
        }
        if ($brands = $this->brands($d['title'].' '.strip_tags($d['description']))) {
            $notes[] = ['type' => 'brand', 'message' => '包含品牌词：'.implode('、', $brands).'（可能侵权，请确认有授权或删除）'];
        }
        if (! $d['images']) {
            $notes[] = ['type' => 'images', 'message' => '没有可用的图片'];
        }
        if (collect($d['variants'] ?? [])->contains(fn ($v) => (int) $v['cost'] <= 0)) {
            $notes[] = ['type' => 'cost', 'message' => '有规格没有采集到价格，请填写成本'];
        }
        if (mb_strlen($d['title']) < 8) {
            $notes[] = ['type' => 'title', 'message' => '标题太短，请补充'];
        }

        return [Item::normalize($d), $notes];
    }

    /** @return string[] */
    public function brands(string $text): array
    {
        $text = mb_strtolower($text);
        $hits = [];
        foreach (self::BRANDS as $b) {
            // Whole word among Latin letters; next to Chinese ("Nike风") it still counts
            if (preg_match('/(?<![\p{Latin}\d])'.preg_quote($b, '/').'(?![\p{Latin}\d])/u', $text)) {
                $hits[] = $b;
            }
        }

        return $hits;
    }
}
