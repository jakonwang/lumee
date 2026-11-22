<?php

namespace App\Themes;

use App\Markets\MarketContext;
use App\Markets\MarketSettings;
use App\Markets\ShoppingCalendar;
use App\Models\Market;
use App\Models\Store;
use App\Models\StoreTheme;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Automatic store design (phase C of docs/STOREFRONT-DESIGN-PLAN.md): builds a market's home page
 * and look from what Lumée knows about it — its region, language, currency, payment methods, duties,
 * what sells there, its collections, customer photos and the next shopping event — following the
 * region's recipe and style kit. The result is always a draft (never published): the merchant
 * reviews it in the editor, where the design check runs before publishing.
 */
class AutoDesigner
{
    /** Market countries → region. */
    public const REGIONS = [
        'us' => ['US', 'CA', 'AU', 'NZ', 'GB', 'IE'],
        'eu' => ['DE', 'FR', 'IT', 'ES', 'NL', 'BE', 'AT', 'PT', 'FI', 'SE', 'DK', 'PL', 'GR', 'CZ', 'HU', 'RO', 'LU', 'CH', 'NO'],
        'latam' => ['MX', 'BR', 'AR', 'CO', 'CL', 'PE', 'UY', 'EC', 'CR', 'GT', 'DO'],
        'sea' => ['SG', 'MY', 'TH', 'ID', 'PH', 'VN'],
        'me' => ['AE', 'SA', 'QA', 'KW', 'BH', 'OM', 'EG', 'JO', 'IL'],
    ];

    public const KITS = ['us' => 'us_clean', 'eu' => 'eu_minimal', 'latam' => 'latam_promo', 'sea' => 'sea_mobile', 'me' => 'me_luxe'];

    /** Home page blocks per region, in order (blocks without data are skipped). */
    public const RECIPES = [
        'us' => ['slideshow', 'marquee', 'market_bestsellers', 'category_circles', 'ugc_wall', 'newsletter'],
        'eu' => ['slideshow', 'trust_bar', 'product_tabs', 'ugc_wall', 'newsletter'],
        'latam' => ['slideshow', 'marquee', 'category_circles', 'flash_sale', 'market_bestsellers', 'trust_bar'],
        'sea' => ['slideshow', 'category_circles', 'flash_sale', 'product_tabs', 'trust_bar'],
        'me' => ['slideshow', 'market_bestsellers', 'ugc_wall', 'trust_bar', 'newsletter'],
    ];

    public function __construct(private ThemeService $themes, private MarketThemes $markets, private BannerComposer $banners) {}

    public static function region(?Market $market, Store $store): string
    {
        $preset = $market ? MarketSettings::of($market)['preset'] : null;
        if (isset(self::KITS[$preset])) {
            return $preset;
        }
        $country = strtoupper((string) ($market ? ((array) $market->countries)[0] ?? '' : ($store->country ?? 'US')));
        foreach (self::REGIONS as $region => $countries) {
            if (in_array($country, $countries, true)) {
                return $region;
            }
        }

        return 'us';
    }

    /**
     * Design a market (null = the main site) on a library theme and save it as the draft.
     * $event = a calendar event key to build a campaign version for (else the next one in its preparation window, if any).
     *
     * @return array{config: array, own_sections: bool, region: string, notes: string[]}
     */
    public function generate(Store $store, StoreTheme $row, ?Market $market, ?string $event = null, bool $save = true): array
    {
        $result = app(MarketContext::class)->within($market, fn () => $this->build($store, $row, $market, $event));
        if ($save) {
            if ($market) {
                $this->markets->saveDraft($row, $market, $result['config'], true);
            } else {
                $row->draft = $result['config'];
                $row->save();
            }
        }

        return $result;
    }

    private function build(Store $store, StoreTheme $row, ?Market $market, ?string $eventKey): array
    {
        $theme = $row->theme;
        $schema = $this->themes->schema($theme);
        $region = self::region($market, $store);
        $lang = DesignCopy::lang($market?->default_locale ?? \App\I18n\Localization::storeDefault($store));
        $data = new SectionData();   // fresh: inside this market
        $notes = [];

        $config = $market ? $this->markets->effective($row, $market) : $this->markets->base($row, true);

        // 1) Look: the region's style kit (fonts the theme offers, the brand's own primary colour kept)
        $kit = $schema['kits'][self::KITS[$region]] ?? null;
        if ($kit) {
            foreach ($kit['settings'] as $path => $want) {
                [$g, $k] = explode('.', $path, 2);
                $field = $schema['settings'][$g]['fields'][$k] ?? null;
                if (! $field) {
                    continue;
                }
                $value = $field['type'] === 'select'
                    ? collect((array) $want)->first(fn ($v) => array_key_exists($v, $field['options']))
                    : (is_array($want) ? $want[0] : $want);
                if ($value !== null) {
                    $config['settings'][$g][$k] = $value;
                }
            }
            $notes[] = __('套用了风格包「:p0」（保留了品牌主色）', ['p0' => __($kit['name'])]);
        }

        // 2) Facts for this market — every number comes from the store's settings
        $facts = $this->facts($store, $market, $lang, $data);
        if ($facts) {
            $config['settings']['header']['announcement_enabled'] = true;
            $config['settings']['header']['announcement_text'] = mb_substr(implode(' · ', array_slice($facts, 0, 2)), 0, 120);
            $config['settings']['product']['perks'] = implode("\n", array_slice($facts, 0, 4));
        }

        // 3) The next shopping event in its preparation window (or the one asked for)
        $event = $this->event($market, $eventKey);
        if ($event) {
            $notes[] = __('首屏用了活动「:p0」的文案（:p1 开始）', ['p0' => __($event['name']), 'p1' => $event['start']]);
        }

        // 4) Home page blocks, following the region's recipe and skipping blocks without data
        $bestsellers = $data->bestsellers(30, 8);
        $collections = $data->collections([], 8);
        $sale = $data->products('sale', null, 8);
        $photos = $data->ugc(8, 4);
        $banner = $this->banners->compose($store, $bestsellers, (string) ($config['settings']['colors']['primary'] ?? '#0E8A5F'), (string) ($config['settings']['colors']['background'] ?? '#FFFFFF'));
        if ($banner) {
            $notes[] = __('用热卖商品的图片合成了首屏横幅（电脑和手机各一张）');
        }

        $sections = [];
        foreach (self::RECIPES[$region] as $type) {
            if (! isset($schema['sections'][$type])) {
                continue;
            }
            $s = $this->themes->newSection($theme, $type, $type.'-auto');
            $set = &$s['settings'];
            switch ($type) {
                case 'slideshow':
                    [$h1, $t1] = $event ? DesignCopy::event($lang, $event['key']) : [DesignCopy::get($lang, 'hero_heading'), DesignCopy::get($lang, 'hero_text')];
                    $set = [...$set, 'slide1_image' => $banner['desktop'] ?? null, 'slide1_mobile_image' => $banner['mobile'] ?? null,
                        'slide1_heading' => $h1, 'slide1_text' => $t1, 'slide1_button' => DesignCopy::get($lang, 'shop_now'), 'slide1_link' => '/collections/all',
                        'slide2_heading' => DesignCopy::get($lang, $event ? 'hero_heading' : 'slide2_heading'), 'slide2_text' => DesignCopy::get($lang, $event ? 'hero_text' : 'slide2_text'),
                        'slide2_button' => DesignCopy::get($lang, 'see_all'), 'slide2_link' => '/collections/all',
                        'slide3_heading' => '', 'slide3_text' => '', 'slide3_button' => '', 'slide3_link' => '',
                        'height' => $region === 'sea' ? 'small' : ($region === 'me' ? 'large' : 'medium'), 'overlay' => $banner ? 30 : 0];
                    break;
                case 'marquee':
                    if (! $facts) {
                        continue 2;
                    }
                    $set['text'] = implode("\n", $facts);
                    $set['style'] = $region === 'latam' ? 'primary' : 'dark';
                    break;
                case 'market_bestsellers':
                    if ($bestsellers->isEmpty()) {
                        continue 2;
                    }
                    $set = [...$set, 'eyebrow' => DesignCopy::get($lang, 'trending'), 'heading' => DesignCopy::get($lang, 'bestsellers'), 'columns' => $region === 'me' ? '3' : '4'];
                    break;
                case 'category_circles':
                    if ($collections->count() < 3) {
                        continue 2;
                    }
                    $set['heading'] = $region === 'us' ? DesignCopy::get($lang, 'shop_by') : '';
                    $set['shape'] = $region === 'sea' ? 'rounded' : 'circle';
                    break;
                case 'flash_sale':
                    $ends = $event ? CarbonImmutable::parse($event['end'])->setTime(23, 59) : null;
                    if ($sale->isEmpty() || (! $ends && ! \App\Models\Promotion::where('enabled', true)->where('ends_at', '>', now())->exists())) {
                        continue 2;
                    }
                    $set = [...$set, 'heading' => DesignCopy::get($lang, 'flash_sale'), 'ends_at' => $ends?->format('Y-m-d\TH:i'), 'limit' => 8];
                    break;
                case 'product_tabs':
                    $set = [...$set, 'heading' => '', 'tab1_label' => DesignCopy::get($lang, 'new_in'), 'tab1_source' => 'newest',
                        'tab2_label' => DesignCopy::get($lang, 'bestsellers'), 'tab2_source' => 'bestsellers',
                        'tab3_label' => $sale->isNotEmpty() ? DesignCopy::get($lang, 'on_sale') : '', 'tab3_source' => 'sale',
                        'mobile_columns' => '2', 'columns' => $region === 'sea' ? '5' : '4'];
                    break;
                case 'ugc_wall':
                    if (count($photos) < 3) {
                        continue 2;
                    }
                    $set['heading'] = DesignCopy::get($lang, 'from_customers');
                    break;
                case 'trust_bar':
                    $set['returns'] = ($r = \App\Returns\Returns::settings($store))['enabled'] ? DesignCopy::get($lang, 'returns', ['days' => $r['window_days']]) : '';
                    break;
                case 'newsletter':
                    $set = [...$set, 'heading' => DesignCopy::get($lang, 'newsletter'), 'text' => DesignCopy::get($lang, 'newsletter_text'), 'button_label' => DesignCopy::get($lang, 'subscribe')];
                    break;
            }
            unset($set);
            $sections[] = $s;
        }
        $config['sections'] = $sections;
        $notes[] = __('首页按「:p0」的模块顺序生成了 :p1 个模块，没有数据的模块已跳过', ['p0' => __(self::regionName($region)), 'p1' => count($sections)]);

        return ['config' => $this->themes->sanitize($theme, $config), 'own_sections' => true, 'region' => $region, 'notes' => $notes];
    }

    /** Selling points for this market, in its language, numbers from the settings (call inside the market's context). @return string[] */
    public function facts(Store $store, ?Market $market, string $lang, SectionData $data): array
    {
        $out = [];
        $min = \App\Models\ShippingRate::where('price', 0)->whereNotNull('min_subtotal')->min('min_subtotal');
        if ($min) {
            $out[] = DesignCopy::get($lang, 'free_shipping_over', ['amount' => self::inLocale($market?->default_locale ?? $lang, fn () => Money::display(app(MarketContext::class)->money((int) $min), $store->currency))]);
        }
        $returns = \App\Returns\Returns::settings($store);
        if ($returns['enabled']) {
            $out[] = DesignCopy::get($lang, 'returns', ['days' => $returns['window_days']]);
        }
        if ($inst = \App\Payments\Installments::settings()) {
            $out[] = DesignCopy::get($lang, 'installments', ['count' => $inst['months']]);
        }
        if (\App\Payments\CashOnDeliveryGateway::settings()) {
            $out[] = DesignCopy::get($lang, 'cod');
        }
        if ($market && MarketSettings::of($market)['duties']['mode'] === 'included') {
            $out[] = DesignCopy::get($lang, 'duties');
        }
        $out[] = DesignCopy::get($lang, 'secure');

        return $out;
    }

    /** The asked-for event, else the next one whose preparation window has started. */
    private function event(?Market $market, ?string $key): ?array
    {
        $events = ShoppingCalendar::upcoming($market ? (array) $market->countries : []);
        if ($key) {
            return collect($events)->firstWhere('key', $key);
        }

        return collect($events)->first(fn ($e) => $e['preparing']);
    }

    /** Run $fn in the shopper's language (money formats like "$980.00" in Mexico, "R$ 245,00" in Brazil), then switch back. */
    private static function inLocale(string $locale, callable $fn): mixed
    {
        $before = app()->getLocale();
        app()->setLocale($locale);
        try {
            return $fn();
        } finally {
            app()->setLocale($before);
        }
    }

    public static function regionName(string $region): string
    {
        return ['us' => '北美', 'eu' => '欧洲', 'latam' => '拉美', 'sea' => '东南亚', 'me' => '中东'][$region] ?? $region;
    }
}
