<?php

namespace App\Themes;

use App\Markets\MarketContext;
use App\Models\Discount;
use App\Models\Market;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreMedia;
use App\Models\StoreTheme;
use App\Models\Translation;
use App\Support\Color;

/**
 * Checks a store design (the main site's or a market's draft) before it goes live:
 *  - numbers in the copy match the settings (free-shipping threshold, returns window)
 *  - no text left in another language
 *  - readable colours (WCAG contrast)
 *  - products visible early on phones; banner images not too heavy
 *  - what the market needs (EU: VAT-included wording)
 *  - links and products that this market actually sells; coupon codes that work; flash sales still running
 *
 * @phpstan-type Issue array{level: 'error'|'warning'|'info', code: string, message: string, place: ?string, block: ?string}
 */
class DesignCheck
{
    private const FREE_SHIPPING = '/free\s+(\w+\s+)?(shipping|delivery)|env[ií]o\s+gratis|frete\s+gr[aá]tis|livraison\s+(gratuite|offerte)|kostenlose[rn]?\s+versand|versandkostenfrei|spedizione\s+gratuita|送料無料|شحن\s+مجاني|包邮|免运费/iu';

    private const RETURNS = '/return|devoluc|troca|retour|r[uü]ckgabe|\bresi\b|返品|إرجاع|退货/iu';

    private const DAYS = '/(\d{1,3})\s*-?\s*(day|days|d[ií]as|jours|tage|giorni|日|يوم|天)/iu';

    private const VAT = '/\bvat\b|mwst|\btva\b|\biva\b|\bbtw\b|\bttc\b|inkl\.|incl\.|tax(es)?\s+included|含税/iu';

    /** Default headings the storefront shows in the shopper's language by itself (see the blocks' templates). */
    private const AUTO_TRANSLATED = ['You may also like', 'You might also like'];

    private const PRODUCT_BLOCKS = ['featured_products', 'market_bestsellers', 'product_tabs', 'flash_sale', 'collection_list', 'category_circles'];

    public function __construct(private ThemeService $themes, private MarketThemes $markets) {}

    /**
     * Check the draft — or $config, a proposal not saved yet (with its market $layer, for what the market wrote itself).
     *
     * @return array<int, Issue>
     */
    public function run(Store $store, StoreTheme $row, ?Market $market, ?array $config = null, ?array $layer = null): array
    {
        return app(MarketContext::class)->within($market, fn () => $this->check($store, $row, $market, $config, $layer));
    }

    private function check(Store $store, StoreTheme $row, ?Market $market, ?array $proposal, ?array $proposalLayer): array
    {
        $schema = $this->themes->schema($row->theme);
        $config = $proposal ?? ($market ? $this->markets->effective($row, $market) : $this->markets->base($row, true));
        $layer = $proposalLayer ?? ($market ? ($this->markets->row($row, $market)?->draft ?? []) : []);
        $issues = [];
        $add = function (string $level, string $code, string $message, ?string $place = null, ?string $block = null) use (&$issues) {
            $issues[] = compact('level', 'code', 'message', 'place', 'block');
        };

        // All text the shopper reads: [key, place, block id, label, value]
        $texts = [];
        foreach ($schema['settings'] as $g => $def) {
            foreach ($def['fields'] as $k => $f) {
                if (in_array($f['type'], ['text', 'textarea'], true) && ! ($g === 'header' && $k === 'announcement_text' && ! $config['settings']['header']['announcement_enabled'])) {
                    $texts[] = ["settings.$g.$k", null, null, __($def['label']).' · '.__($f['label']), (string) ($config['settings'][$g][$k] ?? '')];
                }
            }
        }
        foreach ($this->places($config) as $place => $list) {
            foreach ($list as $s) {
                if (! $s['enabled']) {
                    continue;
                }
                foreach ($schema['sections'][$s['type']]['fields'] ?? [] as $k => $f) {
                    if (in_array($f['type'], ['text', 'textarea'], true) && ! preg_match('/^code\d$/', $k)) {
                        $texts[] = ["sections.{$s['id']}.$k", $place, $s['id'], __($schema['sections'][$s['type']]['label']).' · '.__($f['label']), (string) ($s['settings'][$k] ?? '')];
                    }
                }
            }
        }
        $texts = array_values(array_filter($texts, fn ($t) => trim($t[4]) !== ''));

        // 1) Free-shipping amounts and returns windows written in the copy
        $min = \App\Models\ShippingRate::where('price', 0)->whereNotNull('min_subtotal')->min('min_subtotal');
        $threshold = $min ? app(MarketContext::class)->money((int) $min) / 100 : null;
        $returns = \App\Returns\Returns::settings($store);
        foreach ($texts as [$key, $place, $block, $label, $value]) {
            foreach (preg_split('/\n|·/u', $value) as $line) {
                if (preg_match(self::FREE_SHIPPING, $line)) {
                    $n = self::amount($line);
                    if ($threshold === null && $n !== null) {
                        $add('error', 'free_shipping_missing', __('「:p0」写了满 :p1 免邮，但运费设置里没有免邮门槛', ['p0' => $label, 'p1' => $n]), $place, $block);
                    } elseif ($threshold !== null && $n !== null && abs($n - $threshold) > max(0.01, $threshold * 0.005)) {
                        $add('error', 'free_shipping_amount', __('「:p0」写的免邮门槛是 :p1，运费设置里实际是 :p2', ['p0' => $label, 'p1' => $n, 'p2' => \App\Support\Money::format((int) round($threshold * 100))]), $place, $block);
                    }
                }
                if (preg_match(self::RETURNS, $line) && preg_match(self::DAYS, $line, $m)) {
                    if (! $returns['enabled']) {
                        $add('warning', 'returns_off', __('「:p0」写了 :p1 天退货，但店铺没有开启退货', ['p0' => $label, 'p1' => $m[1]]), $place, $block);
                    } elseif ((int) $m[1] !== (int) $returns['window_days']) {
                        $add('error', 'returns_days', __('「:p0」写的是 :p1 天退货，退货设置里是 :p2 天', ['p0' => $label, 'p1' => $m[1], 'p2' => $returns['window_days']]), $place, $block);
                    }
                }
            }
        }

        // 2) Text not in the market's language
        if ($market) {
            $lang = \App\Themes\DesignCopy::lang($market->default_locale);
            $storeLang = \App\Themes\DesignCopy::lang(\App\I18n\Localization::storeDefault($store));
            $overridden = MarketThemes::localizedKeys($layer);
            $translated = Translation::where('type', 'theme')->where('resource_id', $store->id)->where('locale', $market->default_locale)
                ->whereNotNull('value')->where('value', '!=', '')->pluck('field')->flip();
            $defaults = $this->defaultTexts($schema);
            $missing = [];
            foreach ($texts as [$key, $place, $block, $label, $value]) {
                if (in_array($value, self::AUTO_TRANSLATED, true)) {
                    continue;
                }
                $own = isset($overridden[$key]) || ($place === 'home' && isset($overridden['sections.*']));
                $foreign = $own
                    ? ($lang !== 'en' && isset($defaults[$value]))   // still the theme's English default
                    : ($storeLang !== $lang && ! isset($translated[$key]));
                if ($foreign) {
                    $missing[] = $label;
                }
            }
            if ($missing) {
                $add('warning', 'untranslated', __(':p0 处文字还不是 :p1：:p2', ['p0' => count($missing), 'p1' => \App\I18n\Localization::languages()[$market->default_locale] ?? $market->default_locale,
                    'p2' => implode('、', array_slice(array_unique($missing), 0, 3)).(count($missing) > 3 ? '…' : '')]));
            }
        }

        // 2b) Words the brand kit bans
        $banned = array_filter(\App\Content\BrandKit::for($store)->all()['banned_words']);
        foreach ($texts as [$key, $place, $block, $label, $value]) {
            foreach ($banned as $w) {
                if (mb_stripos($value, $w) !== false) {
                    $add('error', 'banned_word', __('「:p0」用了品牌规范禁用的词「:p1」', ['p0' => $label, 'p1' => $w]), $place, $block);
                }
            }
        }

        // 3) Colour contrast
        $c = $config['settings']['colors'] ?? [];
        if (Color::isHex($c['text'] ?? null) && Color::isHex($c['background'] ?? null) && self::contrast($c['text'], $c['background']) < 4.5) {
            $add('error', 'contrast_text', __('文字颜色和背景色对比度只有 :p0:1，低于 4.5:1，很多人会看不清', ['p0' => round(self::contrast($c['text'], $c['background']), 1)]));
        }
        if (Color::isHex($c['primary'] ?? null) && Color::isHex($c['text'] ?? null)) {
            $on = Color::luminance($c['primary']) > 0.45 ? $c['text'] : '#FFFFFF';
            if (self::contrast($c['primary'], $on) < 3) {
                $add('warning', 'contrast_button', __('按钮主色上的文字对比度只有 :p0:1，建议换一个深一点或浅一点的主色', ['p0' => round(self::contrast($c['primary'], $on), 1)]));
            }
        }

        // 4) Phones: products near the top; banner images not too heavy
        $home = array_values(array_filter($config['sections'], fn ($s) => $s['enabled'] && ($s['settings']['visibility'] ?? 'all') !== 'desktop'));
        $top = array_slice(array_values(array_filter($home, fn ($s) => ! in_array($s['type'], ['marquee', 'trust_bar', 'coupon_bar'], true))), 0, 2);
        if ($home && ! array_intersect(array_column($top, 'type'), self::PRODUCT_BLOCKS)) {
            $region = AutoDesigner::region($market, $store);
            $add(in_array($region, ['sea', 'latam'], true) ? 'warning' : 'info', 'mobile_products', __('手机上前两屏看不到商品；东南亚、拉美的买家习惯一进来就看到商品，可以把商品模块往上移'), 'home');
        }
        $imageIds = [];
        foreach ($config['sections'] as $s) {
            if ($s['enabled'] && in_array($s['type'], ['hero', 'slideshow'], true)) {
                foreach ($s['settings'] as $k => $v) {
                    if (str_contains($k, 'image') && is_int($v)) {
                        $imageIds[$v] = $s['id'];
                    }
                }
            }
        }
        foreach (StoreMedia::whereIn('id', array_keys($imageIds))->where('size', '>', 300 * 1024)->get() as $m) {
            $add('warning', 'heavy_image', __('首屏图片有 :p0 KB，超过 300 KB，手机上会加载得慢；建议压缩或换成 WebP', ['p0' => (int) round($m->size / 1024)]), 'home', $imageIds[$m->id]);
        }

        // 5) What the market needs
        if ($market && AutoDesigner::region($market, $store) === 'eu') {
            $all = implode("\n", array_column($texts, 4));
            if (! preg_match(self::VAT, $all)) {
                $add('warning', 'eu_vat', __('欧洲市场的价格要写明含税（例如在公告或信任条里写 “Prices include VAT” 或当地语言的说法）'));
            }
        }

        // 6) Products and links this market can't sell; coupon codes; flash sales
        $handles = [];
        $productIds = [];
        foreach ($this->places($config) as $place => $list) {
            foreach ($list as $s) {
                if (! $s['enabled']) {
                    continue;
                }
                foreach ($s['settings'] as $k => $v) {
                    if (is_string($v) && preg_match('#^/products/([a-z0-9-]+)#i', $v, $m)) {
                        $handles[$m[1]][] = [$place, $s['id']];
                    }
                    if (str_ends_with($k, '_product') && is_int($v)) {
                        $productIds[$v][] = [$place, $s['id']];
                    }
                }
                if ($s['type'] === 'coupon_bar') {
                    foreach ([1, 2, 3] as $n) {
                        $code = trim((string) ($s['settings']["code$n"] ?? ''));
                        $d = $code !== '' ? Discount::where('code', $code)->first() : null;
                        if ($code !== '' && (! $d || $d->status() !== 'active')) {
                            $add('warning', 'coupon_inactive', __('优惠券条里的折扣码 :p0 现在不能用，不会显示出来', ['p0' => $code]), $place, $s['id']);
                        }
                    }
                }
                if ($s['type'] === 'flash_sale' && ! app(SectionData::class)->flashSale($s['settings'])) {
                    $add('info', 'flash_sale_hidden', __('限时抢购现在不会显示：已经结束，或者没有打折商品 / 正在进行的自动折扣'), $place, $s['id']);
                }
            }
        }
        $sold = Product::active()->where(fn ($q) => $q->whereIn('handle', array_keys($handles))->orWhereIn('id', array_keys($productIds)))->get(['id', 'handle']);
        foreach ($handles as $handle => $where) {
            if (! $sold->firstWhere('handle', $handle)) {
                $add('error', 'link_not_sold', __('有链接指向 /products/:p0，但这个商品在这里买不到（未上架或这个市场不卖）', ['p0' => $handle]), $where[0][0], $where[0][1]);
            }
        }
        foreach ($productIds as $id => $where) {
            if (! $sold->firstWhere('id', $id)) {
                $add('error', 'product_not_sold', __('场景图热点里有商品在这里买不到（未上架或这个市场不卖）'), $where[0][0], $where[0][1]);
            }
        }

        $order = ['error' => 0, 'warning' => 1, 'info' => 2];
        usort($issues, fn ($a, $b) => $order[$a['level']] <=> $order[$b['level']]);

        return $issues;
    }

    /** Block lists by place: home, product / collection / cart, page:{id}. */
    private function places(array $config): array
    {
        $out = ['home' => $config['sections']];
        foreach ((array) ($config['templates'] ?? []) as $k => $list) {
            $out[$k] = (array) $list;
        }
        foreach ((array) ($config['pages'] ?? []) as $id => $list) {
            $out["page:$id"] = (array) $list;
        }

        return $out;
    }

    /** The theme's default (English) texts. @return array<string, true> */
    private function defaultTexts(array $schema): array
    {
        $out = [];
        $walk = function (array $fields) use (&$out) {
            foreach ($fields as $f) {
                if (in_array($f['type'], ['text', 'textarea'], true) && is_string($f['default'] ?? null) && $f['default'] !== '') {
                    $out[$f['default']] = true;
                }
            }
        };
        foreach ($schema['settings'] as $g) {
            $walk($g['fields']);
        }
        foreach ($schema['sections'] as $s) {
            $walk($s['fields']);
        }

        return $out;
    }

    /** First amount in a line: "$1,831.50", "R$ 199,90", "49 €", "1.000" → float. */
    public static function amount(string $line): ?float
    {
        if (! preg_match('/\d[\d.,\s]*\d|\d/u', $line, $m)) {
            return null;
        }
        $n = preg_replace('/\s/u', '', $m[0]);
        $lastDot = strrpos($n, '.');
        $lastComma = strrpos($n, ',');
        $dec = max($lastDot === false ? -1 : $lastDot, $lastComma === false ? -1 : $lastComma);
        if ($dec >= 0 && strlen($n) - $dec - 1 === 3 && substr_count($n, $n[$dec]) >= 1 && ($lastDot === false || $lastComma === false)) {
            $dec = -1;   // "1.000" / "1,000": thousands, not decimals
        }
        $int = $dec >= 0 ? substr($n, 0, $dec) : $n;
        $frac = $dec >= 0 ? substr($n, $dec + 1) : '';

        return (float) (preg_replace('/\D/', '', $int).($frac !== '' ? '.'.preg_replace('/\D/', '', $frac) : ''));
    }

    public static function contrast(string $a, string $b): float
    {
        $l1 = Color::luminance($a);
        $l2 = Color::luminance($b);

        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }
}
