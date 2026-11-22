<?php

namespace App\Content;

use App\I18n\Localization;
use App\Markets\MarketContext;
use App\Models\Product;
use App\Models\Store;
use App\Models\Translation;

/**
 * Content quality score (0–100) of a product against the brand kit, with every check explained:
 *   title 20 · images 30 · description 20 · attributes 15 · translations 10 · SEO 5
 * Stored on products.content_score so lists can sort and filter by it.
 */
class ContentScorer
{
    /** @return array{score: int, checks: array<int, array{key: string, group: string, points: int, max: int, message: ?string}>} */
    public function score(Store $store, Product $product): array
    {
        $kit = BrandKit::for($store);
        $k = $kit->all();
        $product->loadMissing('images', 'variants');
        $raw = fn (string $f) => $product->getRawOriginal($f) ?? $product->getAttributes()[$f] ?? null;   // never the translated value
        $title = trim((string) $raw('title'));
        $html = (string) $raw('description');
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
        $checks = [];
        $add = function (string $key, string $group, int $max, float $ratio, ?string $message) use (&$checks) {
            $checks[] = ['key' => $key, 'group' => $group, 'points' => (int) round($max * max(0, min(1, $ratio))), 'max' => $max, 'message' => $ratio >= 1 ? null : $message];
        };

        // Title (20)
        $len = mb_strlen($title);
        $add('title_length', 'title', 10, $len >= $k['title_min'] && $len <= $k['title_max'] ? 1 : ($len > 0 ? 0.5 : 0),
            "标题长度 {$len} 个字符，建议 {$k['title_min']}–{$k['title_max']} 个");
        $banned = array_values(array_filter($kit->bannedWords(), fn ($w) => $w !== '' && mb_stripos($title, $w) !== false));
        $add('title_words', 'title', 5, $banned ? 0 : 1, '标题里有不该出现的词：'.implode('、', $banned));
        $shouting = $title !== '' && mb_strtoupper($title) === $title && preg_match('/[A-Z]{4,}/', $title);
        $foreign = ! str_starts_with((string) $store->locale, 'zh') && preg_match('/\p{Han}/u', $title);
        $add('title_style', 'title', 5, $shouting || $foreign ? 0 : 1, $foreign ? '标题里有中文，需要翻译' : '标题全是大写');

        // Images (30)
        $images = $product->images;
        $add('image_count', 'images', 15, $images->count() / $k['min_images'], "只有 {$images->count()} 张图片，建议至少 {$k['min_images']} 张");
        $main = $images->first();
        $target = BrandKit::ratio($k['ratio']);
        $ratioOk = $main && $main->width && $main->height && abs($main->width / $main->height - $target) <= 0.03;
        $add('image_ratio', 'images', 10, $ratioOk ? 1 : 0, $main ? "主图比例和品牌规范（{$k['ratio']}）不一致，可以一键统一" : '没有主图');
        $short = $main ? min((int) $main->width, (int) $main->height) : 0;
        $add('image_size', 'images', 5, $short >= 800 ? 1 : ($short >= 500 ? 0.5 : 0), "主图太小（{$short}px），建议短边至少 800px");

        // Description (20)
        $add('desc_length', 'description', 8, mb_strlen($text) / 200, '描述太短，建议至少 200 个字符，写清材质、尺寸和用途');
        $add('desc_bullets', 'description', 6, preg_match('#<li\b#i', $html) ? 1 : 0, '描述里没有要点列表，买家不容易扫读');
        $supplier = preg_match('#<img\b|<a\b|\p{Han}#u', $html) && ! str_starts_with((string) $store->locale, 'zh');
        $add('desc_clean', 'description', 6, $supplier ? 0 : 1, '描述里有外部图片、链接或中文，像是供应商原文');

        // Attributes (15)
        $add('type', 'attributes', 5, trim((string) $product->product_type) !== '' ? 1 : 0, '没有填写商品类型');
        $add('tags', 'attributes', 5, count((array) $product->tags) / 3, '标签少于 3 个');
        $withWeight = $product->variants->filter(fn ($v) => (int) $v->weight > 0)->count();
        $add('weight', 'attributes', 5, $product->variants->count() ? $withWeight / $product->variants->count() : 0, '有规格没有填写重量（计算运费需要）');

        // Translations (10): every language the store sells in besides the default
        $languages = $this->languages($store);
        if ($languages) {
            $done = Translation::where('type', 'product')->where('resource_id', $product->id)->whereIn('locale', $languages)
                ->whereIn('field', ['title', 'description'])->where('value', '!=', '')->get()
                ->groupBy('locale')->filter(fn ($g) => $g->count() >= 2)->keys()->all();
            $missing = array_values(array_diff($languages, $done));
            $add('translations', 'translations', 10, count($done) / count($languages), '还没有翻译：'.implode('、', array_map(fn ($l) => Localization::languages()[$l] ?? $l, $missing)));
        } else {
            $add('translations', 'translations', 10, 1, null);
        }

        // SEO (5)
        $seo = (array) $raw('seo');
        if (is_string($raw('seo'))) {
            $seo = (array) json_decode($raw('seo'), true);
        }
        $add('seo', 'seo', 5, (trim((string) ($seo['title'] ?? '')) !== '' ? 0.6 : 0) + (trim((string) ($seo['description'] ?? '')) !== '' ? 0.4 : 0), '没有单独设置 SEO 标题或描述');

        return ['score' => array_sum(array_column($checks, 'points')), 'checks' => $checks];
    }

    /** Score and remember it on the product. */
    public function rescore(Store $store, Product $product): int
    {
        $s = $this->score($store, $product)['score'];
        Product::whereKey($product->id)->update(['content_score' => $s, 'content_scored_at' => now()]);

        return $s;
    }

    /** Languages shoppers see besides the default: published languages and every market's. */
    public function languages(Store $store): array
    {
        $default = Localization::storeDefault($store);
        $all = Localization::enabled($store);
        foreach (MarketContext::live($store) as $m) {
            array_push($all, ...$m->languages());
        }

        return array_values(array_diff(array_unique($all), [$default]));
    }
}
