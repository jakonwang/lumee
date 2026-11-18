<?php

namespace App\Themes;

use App\Models\Collection;
use Illuminate\Support\Str;

/**
 * Converts a Shopify theme's *content and settings* into a Lumée theme config.
 *
 * Liquid can't run here, so the theme's code isn't imported; what carries over is what the merchant
 * set up in Shopify's theme editor:
 *  - config/settings_data.json → colors, fonts, announcement bar
 *  - templates/index.json (Online Store 2.0) or settings_data "content_for_index" (older themes)
 *    → home page sections, mapped to the closest Lumée section (banner → hero, featured collection →
 *    featured products, multicolumn → features, collapsible content → FAQ…)
 *  - collections are matched by handle; Shopify-hosted images can't be fetched and are reported.
 * The result still goes through ThemeService::sanitize(), so nothing outside the schema survives.
 */
class ShopifyThemeConverter
{
    /** Shopify section type → Lumée section type */
    private const MAP = [
        'hero' => ['image-banner', 'slideshow', 'image-hero', 'hero', 'banner', 'hero-banner', 'image-with-text-overlay', 'main-banner'],
        'featured_products' => ['featured-collection', 'featured-products', 'product-grid', 'collection-products', 'featured-collection-grid', 'product-list'],
        'collection_list' => ['collection-list', 'collections-list', 'collection-grid', 'featured-collections', 'list-collections'],
        'image_text' => ['image-with-text', 'image-text', 'featured-row', 'multirow', 'image-with-text-split'],
        'rich_text' => ['rich-text', 'text', 'custom-content', 'text-block', 'rich-text-section'],
        'newsletter' => ['newsletter', 'email-signup-banner', 'email-signup', 'newsletter-signup'],
        'features' => ['multicolumn', 'text-columns-with-images', 'icons-with-text', 'icon-with-text', 'text-columns', 'feature-columns', 'features'],
        'faq' => ['collapsible-content', 'faq', 'accordion', 'collapsible-tabs'],
        'video' => ['video', 'featured-video', 'video-section'],
        'testimonials' => ['testimonials', 'testimonial', 'quotes', 'customer-reviews', 'testimonial-slider'],
    ];

    private array $locale = [];

    private array $report = ['sections' => [], 'settings' => [], 'notes' => []];

    private int $images = 0;

    /**
     * @param  array<string, string>  $files  path (as in the zip) => contents
     * @return array{config: array, report: array}
     */
    public function convert(array $files, string $theme): array
    {
        $json = fn (string $suffix) => $this->decode($this->find($files, $suffix));
        foreach ($files as $path => $content) {
            if (preg_match('~locales/[a-z-]+\.default(\.schema)?\.json$~i', $path)) {
                $this->locale = array_replace_recursive($this->locale, $this->decode($content) ?? []);
            }
        }

        $data = $json('config/settings_data.json') ?? [];
        $current = $data['current'] ?? [];
        if (is_string($current)) {
            $current = $data['presets'][$current] ?? [];
        }
        $index = $json('templates/index.json');
        $headerGroup = $json('sections/header-group.json');

        $settings = $this->settings($current, $theme);
        $this->announcement($settings, [$headerGroup['sections'] ?? [], $current['sections'] ?? []]);

        // Home page: OS 2.0 JSON template, else the older content_for_index list
        if ($index && isset($index['sections'])) {
            $sections = $index['sections'];
            $order = $index['order'] ?? array_keys($sections);
        } else {
            $sections = $current['sections'] ?? [];
            $order = $current['content_for_index'] ?? [];
        }

        $out = [];
        $hero = 0;
        foreach ($order as $id) {
            $src = $sections[$id] ?? null;
            if (! is_array($src) || ! isset($src['type'])) {
                continue;
            }
            $type = $this->target((string) $src['type']);
            if ($type === 'hero' && ++$hero > 3) {
                $type = null;
            }
            if (! $type) {
                $this->report['sections'][] = ['from' => $src['type'], 'to' => null];

                continue;
            }
            $values = $this->{'to'.Str::studly($type)}($src);
            $out[] = ['type' => $type, 'enabled' => ! ($src['disabled'] ?? false), 'settings' => array_filter($values, fn ($v) => $v !== null)];
            $this->report['sections'][] = ['from' => $src['type'], 'to' => $type];
            $this->countImages($src);
        }

        if (! $index && ! $order) {
            $this->report['notes'][] = __('没有找到首页模板（templates/index.json），只导入了颜色、字体等设置。');
        }
        if ($this->images) {
            $this->report['notes'][] = __('有 :p0 张图片存放在 Shopify 的文件库里，无法随主题导入，请在编辑器里重新上传。', ['p0' => $this->images]);
        }

        return ['config' => ['settings' => $settings, 'sections' => $out], 'report' => $this->report];
    }

    // ── Global settings ─────────────────────────────────────────────────────

    private function settings(array $c, string $theme): array
    {
        $out = [];
        $scheme = function (?string $id) use ($c) {
            $schemes = $c['color_schemes'] ?? [];
            $s = $id && isset($schemes[$id]) ? $schemes[$id] : (is_array($schemes) ? reset($schemes) : null);

            return $s['settings'] ?? [];
        };
        $main = $scheme('scheme-1');
        $sale = $scheme($c['sale_badge_color_scheme'] ?? null);

        $colors = [
            'primary' => [$main['button'] ?? null, $c['colors_accent_1'] ?? null, $c['color_button'] ?? null, $c['color_primary'] ?? null, $c['accent_color'] ?? null, $c['color_accent'] ?? null],
            'background' => [$main['background'] ?? null, $c['colors_background_1'] ?? null, $c['color_body_bg'] ?? null, $c['color_background'] ?? null, $c['background_color'] ?? null],
            'text' => [$main['text'] ?? null, $c['colors_text'] ?? null, $c['color_body_text'] ?? null, $c['color_text'] ?? null, $c['text_color'] ?? null],
            'accent' => [($c['sale_badge_color_scheme'] ?? null) ? ($sale['background'] ?? null) : null, $c['colors_accent_2'] ?? null, $c['color_sale_text'] ?? null, $c['color_secondary'] ?? null],
        ];
        foreach ($colors as $key => $candidates) {
            foreach ($candidates as $v) {
                if ($hex = $this->hex($v)) {
                    $out['colors'][$key] = $hex;
                    $this->report['settings'][] = 'colors.'.$key;
                    break;
                }
            }
        }

        $fields = config("themes.$theme.settings.typography.fields");
        foreach (['heading_font' => ['type_header_font', 'type_heading_font', 'font_heading'], 'body_font' => ['type_body_font', 'font_body', 'type_base_font']] as $key => $names) {
            foreach ($names as $n) {
                if (! is_string($c[$n] ?? null)) {
                    continue;
                }
                $family = Str::title(str_replace('_', ' ', preg_replace('/_[a-z]\d$/', '', $c[$n])));
                $match = collect(array_keys($fields[$key]['options'] ?? []))->first(fn ($o) => strcasecmp($o, $family) === 0);
                if ($match) {
                    $out['typography'][$key] = $match;
                    $this->report['settings'][] = 'typography.'.$key;
                } else {
                    $this->report['notes'][] = __('字体 :p0 不在可选字体里，已使用主题默认字体。', ['p0' => $family]);
                }
                break;
            }
        }

        return $out;
    }

    private function announcement(array &$settings, array $groups): void
    {
        foreach ($groups as $group) {
            foreach ((array) $group as $s) {
                if (! is_array($s)) {
                    continue;
                }
                $type = $s['type'] ?? '';
                $text = $link = null;
                if (in_array($type, ['announcement-bar', 'announcement'], true)) {
                    $b = $this->block($s, ['announcement', 'message', 'text']) ?: ($s['settings'] ?? []);
                    $text = $b['text'] ?? $b['message'] ?? null;
                    $link = $b['link'] ?? $b['announcement_link'] ?? null;
                } elseif ($type === 'header' && ($s['settings']['announcement_bar_enabled'] ?? false)) {
                    $text = $s['settings']['announcement_text'] ?? null;
                    $link = $s['settings']['announcement_link'] ?? null;
                }
                if ($text = $this->text($text)) {
                    $settings['header']['announcement_enabled'] = ! ($s['disabled'] ?? false);
                    $settings['header']['announcement_text'] = $text;
                    $settings['header']['announcement_link'] = $this->link($link);
                    $this->report['settings'][] = 'header.announcement';

                    return;
                }
            }
        }
    }

    // ── Sections ────────────────────────────────────────────────────────────

    private function toHero(array $s): array
    {
        $st = $s['settings'] ?? [];
        $slide = $this->block($s, ['slide', 'image', 'banner']);
        $heading = $this->block($s, ['heading', 'title']);
        $text = $this->block($s, ['text', 'subheading', 'paragraph']);
        $buttons = $this->block($s, ['buttons', 'button']);
        $height = $st['image_height'] ?? $st['slide_height'] ?? $st['banner_height'] ?? $st['section_height'] ?? null;

        return [
            'eyebrow' => $this->text($slide['subheading'] ?? $st['subheading'] ?? $st['eyebrow'] ?? null, true) ?: null,
            'heading' => $this->text($heading['heading'] ?? $slide['heading'] ?? $st['heading'] ?? $st['title'] ?? null, true),
            'text' => $this->text($text['text'] ?? $slide['text'] ?? $st['text'] ?? $st['description'] ?? null),
            'button_label' => $this->text($buttons['button_label_1'] ?? $buttons['button_label'] ?? $slide['button_label'] ?? $st['button_label'] ?? null, true),
            'button_link' => $this->link($buttons['button_link_1'] ?? $buttons['button_link'] ?? $slide['link'] ?? $slide['button_link'] ?? $st['button_link'] ?? $st['link'] ?? null),
            'height' => match ($height) { 'small' => 'small', 'large', 'full_screen', 'full', 'adapt_large' => 'large', null => null, default => 'medium' },
            'overlay' => isset($st['image_overlay_opacity']) && is_numeric($st['image_overlay_opacity']) ? (int) $st['image_overlay_opacity'] : null,
        ];
    }

    private function toFeaturedProducts(array $s): array
    {
        $st = $s['settings'] ?? [];
        $cols = (int) ($st['columns_desktop'] ?? $st['products_per_row'] ?? $st['grid'] ?? 4);

        return [
            'eyebrow' => $this->text($st['subheading'] ?? null, true) ?: '',
            'heading' => $this->text($st['title'] ?? $st['heading'] ?? null, true),
            'collection' => $this->collection($st['collection'] ?? null),
            'limit' => $st['products_to_show'] ?? $st['product_count'] ?? $st['limit'] ?? null,
            'columns' => $cols >= 4 ? '4' : '3',
        ];
    }

    private function toCollectionList(array $s): array
    {
        $st = $s['settings'] ?? [];
        $out = ['heading' => $this->text($st['title'] ?? $st['heading'] ?? null, true), 'eyebrow' => ''];
        $i = 0;
        foreach ($this->blocks($s) as $b) {
            if ($i < 4 && ($id = $this->collection($b['settings']['collection'] ?? null))) {
                $out['collection_'.(++$i)] = $id;
            }
        }

        return $out;
    }

    private function toImageText(array $s): array
    {
        $st = $s['settings'] ?? [];
        $row = $this->block($s, ['row']);   // multirow: first row
        $heading = $this->block($s, ['heading', 'title']);
        $text = $this->block($s, ['text', 'paragraph', 'content']);
        $sub = $this->block($s, ['caption', 'subheading']);
        $button = $this->block($s, ['button', 'buttons']);
        $layout = $st['layout'] ?? $st['image_position'] ?? $st['desktop_image_placement'] ?? null;

        return [
            'image_position' => in_array($layout, ['text_first', 'right', 'image_right'], true) ? 'right' : 'left',
            'eyebrow' => $this->text($sub['caption'] ?? $sub['subheading'] ?? $row['caption'] ?? null, true) ?: '',
            'heading' => $this->text($heading['heading'] ?? $row['heading'] ?? $st['title'] ?? $st['heading'] ?? null, true),
            'text' => $this->text($text['text'] ?? $text['content'] ?? $row['text'] ?? $st['text'] ?? null),
            'button_label' => $this->text($button['button_label'] ?? $button['button_label_1'] ?? $row['button_label'] ?? $st['button_label'] ?? null, true) ?: '',
            'button_link' => $this->link($button['button_link'] ?? $button['button_link_1'] ?? $row['button_link'] ?? $st['button_link'] ?? null),
        ];
    }

    private function toRichText(array $s): array
    {
        $st = $s['settings'] ?? [];
        $heading = $this->block($s, ['heading', 'title']);
        $sub = $this->block($s, ['caption', 'subheading']);
        $texts = collect($this->blocks($s))->filter(fn ($b) => in_array($b['type'] ?? '', ['text', 'paragraph', 'content', 'rich_text'], true))
            ->map(fn ($b) => $this->text($b['settings']['text'] ?? $b['settings']['content'] ?? null))->filter()->implode("\n\n");

        return [
            'eyebrow' => $this->text($sub['caption'] ?? $sub['subheading'] ?? null, true) ?: '',
            'heading' => $this->text($heading['heading'] ?? $st['heading'] ?? $st['title'] ?? null, true),
            'text' => $texts ?: $this->text($st['text'] ?? $st['content'] ?? null),
            'align' => in_array($st['desktop_content_alignment'] ?? $st['content_alignment'] ?? $st['text_alignment'] ?? $st['align_text'] ?? 'center', ['left', 'start'], true) ? 'left' : 'center',
        ];
    }

    private function toNewsletter(array $s): array
    {
        $st = $s['settings'] ?? [];
        $heading = $this->block($s, ['heading', 'title']);
        $text = $this->block($s, ['paragraph', 'text', 'subheading']);

        return [
            'heading' => $this->text($heading['heading'] ?? $st['heading'] ?? $st['title'] ?? null, true),
            'text' => $this->text($text['text'] ?? $st['text'] ?? $st['subheading'] ?? null),
        ];
    }

    private function toFeatures(array $s): array
    {
        $st = $s['settings'] ?? [];
        $out = ['heading' => $this->text($st['title'] ?? $st['heading'] ?? null, true) ?: ''];
        $i = 0;
        foreach ($this->blocks($s) as $b) {
            $bs = $b['settings'] ?? [];
            $title = $this->text($bs['title'] ?? $bs['heading'] ?? null, true);
            $text = $this->text($bs['text'] ?? $bs['content'] ?? null);
            if (($title || $text) && $i < 3) {
                $i++;
                $out["item{$i}_title"] = $title;
                $out["item{$i}_text"] = Str::limit(str_replace("\n", ' ', $text), 140, '');
            }
        }
        for ($j = $i + 1; $j <= 3 && $i > 0; $j++) {
            $out["item{$j}_title"] = $out["item{$j}_text"] = '';
        }

        return $out;
    }

    private function toFaq(array $s): array
    {
        $st = $s['settings'] ?? [];
        $out = ['heading' => $this->text($st['heading'] ?? $st['title'] ?? null, true)];
        $i = 0;
        foreach ($this->blocks($s) as $b) {
            $bs = $b['settings'] ?? [];
            $q = $this->text($bs['heading'] ?? $bs['title'] ?? $bs['question'] ?? null, true);
            if ($q && $i < 5) {
                $i++;
                $out["q$i"] = $q;
                $out["a$i"] = $this->text($bs['row_content'] ?? $bs['content'] ?? $bs['answer'] ?? $bs['text'] ?? null);
            }
        }
        for ($j = $i + 1; $j <= 5 && $i > 0; $j++) {
            $out["q$j"] = $out["a$j"] = '';
        }

        return $out;
    }

    private function toVideo(array $s): array
    {
        $st = $s['settings'] ?? [];
        $url = $st['video_url'] ?? $st['video_link'] ?? $st['video'] ?? null;
        if (is_array($url)) {
            $url = $url['url'] ?? null;
        }

        return [
            'heading' => $this->text($st['heading'] ?? $st['title'] ?? null, true) ?: '',
            'video_url' => is_string($url) && VideoEmbed::url($url) ? $url : '',
            'text' => $this->text($st['description'] ?? $st['text'] ?? null),
            'width' => ($st['full_width'] ?? false) ? 'full' : 'narrow',
        ];
    }

    private function toTestimonials(array $s): array
    {
        $st = $s['settings'] ?? [];
        $out = ['heading' => $this->text($st['title'] ?? $st['heading'] ?? null, true)];
        $i = 0;
        foreach ($this->blocks($s) as $b) {
            $bs = $b['settings'] ?? [];
            $quote = $this->text($bs['quote'] ?? $bs['testimonial'] ?? $bs['text'] ?? $bs['content'] ?? null);
            if ($quote && $i < 3) {
                $i++;
                $out["quote$i"] = $quote;
                $out["author$i"] = $this->text($bs['author'] ?? $bs['name'] ?? $bs['author_name'] ?? null, true);
            }
        }
        for ($j = $i + 1; $j <= 3 && $i > 0; $j++) {
            $out["quote$j"] = $out["author$j"] = '';
        }

        return $out;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function target(string $type): ?string
    {
        $type = strtolower(preg_replace('/^(section-|home-|index-)/', '', $type));
        foreach (self::MAP as $to => $from) {
            if (in_array($type, $from, true)) {
                return $to;
            }
        }

        return null;
    }

    /** Blocks in display order. */
    private function blocks(array $s): array
    {
        $blocks = is_array($s['blocks'] ?? null) ? $s['blocks'] : [];
        $order = $s['block_order'] ?? array_keys($blocks);

        return collect($order)->map(fn ($id) => $blocks[$id] ?? null)
            ->filter(fn ($b) => is_array($b) && ! ($b['disabled'] ?? false))->values()->all();
    }

    /** Settings of the first block of one of these types. */
    private function block(array $s, array $types): array
    {
        foreach ($this->blocks($s) as $b) {
            if (in_array($b['type'] ?? '', $types, true)) {
                return $b['settings'] ?? [];
            }
        }

        return [];
    }

    /** Rich text / translation key → plain text. */
    private function text(mixed $v, bool $oneLine = false): string
    {
        if (! is_string($v)) {
            return '';
        }
        if (str_starts_with($v, 't:')) {
            $v = data_get($this->locale, substr($v, 2));
            if (! is_string($v)) {
                return '';
            }
        }
        $v = preg_replace(['~<br\s*/?>~i', '~</(p|li|h[1-6]|div)>~i'], "\n", $v);
        $v = html_entity_decode(strip_tags($v), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $v = trim(preg_replace(["/[ \t]+/", "/\n{3,}/"], [' ', "\n\n"], $v));

        return $oneLine ? trim(preg_replace('/\s+/', ' ', $v)) : $v;
    }

    private function link(mixed $v): string
    {
        if (! is_string($v) || ($v = trim($v)) === '') {
            return '';
        }
        if (preg_match('~^shopify://(collections|products|pages|blogs)/([A-Za-z0-9_-]+)~', $v, $m)) {
            return "/{$m[1]}/{$m[2]}";
        }
        if (preg_match('~^(/(?!/)|#|https?://)~i', $v)) {
            return $v;
        }

        return '';
    }

    private function collection(mixed $handle): ?int
    {
        if (! is_string($handle) || $handle === '') {
            return null;
        }
        $id = Collection::where('handle', $handle)->value('id');
        if (! $id) {
            $this->report['notes'][] = __('店铺里没有 handle 为「:p0」的分类，相关模块会显示最新商品。', ['p0' => $handle]);
        }

        return $id;
    }

    private function hex(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $v = trim($v);
        if (preg_match('/^#([0-9a-f]{3})$/i', $v, $m)) {
            $v = '#'.$m[1][0].$m[1][0].$m[1][1].$m[1][1].$m[1][2].$m[1][2];
        }

        return preg_match('/^#[0-9a-f]{6}$/i', $v) ? strtoupper($v) : null;
    }

    private function countImages(array $s): void
    {
        array_walk_recursive($s, function ($v) {
            if (is_string($v) && str_starts_with($v, 'shopify://shop_images/')) {
                $this->images++;
            }
        });
    }

    /** Shopify JSON files start with a comment block. */
    private function decode(?string $content): ?array
    {
        if ($content === null) {
            return null;
        }
        $content = preg_replace('~^\xEF\xBB\xBF~', '', $content);
        $content = preg_replace('~^\s*/\*.*?\*/~s', '', $content);
        $data = json_decode($content, true);

        return is_array($data) ? $data : null;
    }

    private function find(array $files, string $suffix): ?string
    {
        foreach ($files as $path => $content) {
            if ($path === $suffix || str_ends_with($path, '/'.$suffix)) {
                return $content;
            }
        }

        return null;
    }
}
