<?php

namespace App\Themes;

use App\Models\Store;
use App\Support\Color;
use Illuminate\Support\Arr;

/** Everything a theme template needs: settings, sections, image URLs, derived CSS. */
class ThemeContext
{
    public function __construct(
        public readonly Store $store,
        public readonly array $config,
        private readonly array $media,
        public readonly bool $preview,
        public readonly string $theme = 'aurora',
        /** Values that came from the current market's own design (already in its language). @var array<string, true> */
        private readonly array $localized = [],
    ) {}

    /**
     * Blade view name for a template, looked up in this theme first, then its parent
     * theme(s) (schema "extends"). Lets a theme override only what it changes.
     */
    public function view(string $template): string
    {
        $theme = $this->theme;
        $seen = [];
        while ($theme && ! isset($seen[$theme])) {
            $seen[$theme] = true;
            if (\Illuminate\Support\Facades\View::exists("themes.$theme.$template")) {
                return "themes.$theme.$template";
            }
            $theme = config("themes.$theme.extends");
        }

        return "themes.{$this->theme}.$template";   // let Blade report the missing view
    }

    public function setting(string $path, mixed $default = null): mixed
    {
        $value = Arr::get($this->config['settings'], $path, $default);
        if (isset($this->localized["settings.$path"])) {
            return $value;   // the market's own text, already in its language
        }

        // Text settings (announcement, footer tagline, perks…) in the shopper's language when translated
        return is_string($value) && $value !== ''
            ? app(\App\I18n\Localization::class)->pick('theme', $this->store->id, "settings.{$path}", $value)
            : $value;
    }

    /** Home page blocks. @return array<int, array{id:string,type:string,enabled:bool,settings:array}> */
    public function sections(): array
    {
        // A market's own home page is written in its language
        return $this->prepare($this->config['sections'], isset($this->localized['sections.*']));
    }

    /** Blocks of the product / collection / cart page. */
    public function templateSections(string $template): array
    {
        return $this->prepare((array) ($this->config['templates'][$template] ?? []), false);
    }

    /** Blocks of a campaign landing page (null = the theme has none for it yet). */
    public function pageSections(int $pageId): ?array
    {
        $pages = (array) ($this->config['pages'] ?? []);

        return isset($pages[(string) $pageId]) ? $this->prepare((array) $pages[(string) $pageId], false) : null;
    }

    private function prepare(array $list, bool $ownLanguage): array
    {
        // In the editor preview, hidden sections are still skipped — what you see is what gets published
        $l10n = app(\App\I18n\Localization::class);
        $sections = array_values(array_filter($list, fn ($s) => $s['enabled']));
        // Fields a market overrides are already in its language
        if (! $l10n->translating() || $ownLanguage) {
            return $sections;
        }

        return array_map(function ($s) use ($l10n) {
            foreach ($s['settings'] as $key => $value) {
                if (is_string($value) && $value !== '' && ! isset($this->localized["sections.{$s['id']}.$key"])) {
                    $s['settings'][$key] = $l10n->pick('theme', $this->store->id, "sections.{$s['id']}.{$key}", $value);
                }
            }

            return $s;
        }, $sections);
    }

    public function image(?int $id): ?string
    {
        return $id ? ($this->media[$id] ?? null) : null;
    }

    /** Image shown for products/collections without a photo (per theme, see config/themes.php). */
    public function placeholderUrl(): string
    {
        return config("themes.{$this->theme}.placeholder", '/brand/placeholder.svg');
    }

    public function logoUrl(): ?string
    {
        return $this->image($this->setting('header.logo'));
    }

    /** @return string[] */
    public function perks(): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", (string) $this->setting('product.perks')))));
    }

    /** CSS custom properties derived from the merchant's colors and fonts (all values validated). */
    public function cssVariables(): string
    {
        $p = $this->setting('colors.primary');
        $bg = $this->setting('colors.background');
        $text = $this->setting('colors.text');
        $accent = $this->setting('colors.accent');
        $white = '#FFFFFF';
        $black = '#000000';

        $vars = [
            '--rose-gold-700' => Color::mix($p, $black, 0.22),
            '--rose-gold-600' => Color::mix($p, $black, 0.1),
            '--rose-gold-500' => $p,
            '--rose-gold-300' => Color::mix($p, $white, 0.5),
            '--rose-gold-100' => Color::mix($p, $white, 0.8),
            '--rose-gold-50' => Color::mix($p, $white, 0.9),
            '--ivory' => $bg,
            '--surface-muted' => Color::mix($bg, $p, 0.07),
            '--border' => Color::mix($bg, $p, 0.2),
            '--ink' => $text,
            '--ink-muted' => Color::mix($text, $bg, 0.42),
            '--gem-blush' => $accent,
            '--gradient-metal' => 'linear-gradient(180deg, '.Color::mix($p, $white, 0.15).' 0%, '.Color::mix($p, $black, 0.06).' 100%)',
            '--on-primary' => Color::luminance($p) > 0.45 ? $text : '#FFFFFF',
            '--on-accent' => Color::luminance($accent) > 0.45 ? Color::mix($accent, $black, 0.6) : '#FFFFFF',
            // Latin letters from the theme's fonts; other scripts (Arabic, Japanese…) from the script font
            '--font-display' => "'".$this->setting('typography.heading_font')."', ".(($sf = self::scriptFont()) ? "'$sf', " : '')."'Noto Serif SC', serif",
            '--font-body' => "'".$this->setting('typography.body_font')."', ".($sf ? "'$sf', " : '')."-apple-system, 'PingFang SC', 'Microsoft YaHei', sans-serif",
        ];

        return implode(' ', array_map(fn ($k, $v) => "$k: $v;", array_keys($vars), $vars));
    }

    /** Fonts for scripts the theme's (Latin) fonts don't cover, by language. */
    public const SCRIPT_FONTS = [
        'ar' => 'Noto Sans Arabic', 'he' => 'Noto Sans Hebrew', 'fa' => 'Noto Sans Arabic', 'ur' => 'Noto Nastaliq Urdu',
        'ja' => 'Noto Sans JP', 'ko' => 'Noto Sans KR', 'zh' => 'Noto Sans SC', 'th' => 'Noto Sans Thai', 'vi' => null,
    ];

    /** The script font for the shopper's language, if it needs one. */
    public static function scriptFont(): ?string
    {
        return self::SCRIPT_FONTS[strtolower(substr(app()->getLocale(), 0, 2))] ?? null;
    }

    /**
     * CSS for the "style" settings (corners, buttons, cards, image ratio, spacing), printed after the
     * theme's own styles so it wins. Every value comes from a select field, so it's from a fixed list.
     */
    public function styleCss(): string
    {
        $s = fn (string $k) => (string) $this->setting("style.$k");
        $css = [];
        if (($r = $s('radius')) !== '' && $r !== 'theme') {
            $css[] = ":root { --radius-card: {$r}px; }";
        }
        $css[] = match ($s('buttons')) {
            'square' => '.btn { border-radius: 0; }',
            'rounded' => '.btn { border-radius: 8px; }',
            'pill' => '.btn { border-radius: 999px; }',
            default => '',
        };
        $css[] = match ($s('cards')) {
            'plain' => '.card .img { border: 0; box-shadow: none; }',
            'bordered' => '.card { border: 1px solid var(--border); border-radius: var(--radius-card); padding: 10px; background: var(--surface); } .card .img { border: 0; }',
            'shadow' => '.card { border-radius: var(--radius-card); padding: 10px; background: var(--surface); box-shadow: 0 6px 22px rgba(0,0,0,.07); } .card .img { border: 0; }',
            default => '',
        };
        if (($ratio = $s('image_ratio')) !== '' && $ratio !== '4/5') {
            $css[] = '.card .img { aspect-ratio: '.str_replace('/', ' / ', $ratio).'; }';
        }
        $css[] = match ($s('density')) {
            'compact' => ':root { --section-space: 36px; } .section { padding-block: 36px; } @media (max-width: 720px) { .section { padding-block: 28px; } }',
            'airy' => ':root { --section-space: 104px; } .section { padding-block: 104px; } @media (max-width: 720px) { .section { padding-block: 64px; } }',
            default => '',
        };

        return implode("\n", array_filter($css));
    }

    public function fontsUrl(): string
    {
        // Google Fonts rejects the whole request if a family lacks a requested weight
        $weights = ['Marcellus' => '400', 'Bebas Neue' => '400', 'Lato' => '400;700', 'Noto Nastaliq Urdu' => '400;700'];
        $families = array_values(array_unique(array_filter([$this->setting('typography.heading_font'), $this->setting('typography.body_font'), self::scriptFont()])));
        $q = implode('&', array_map(
            fn ($f) => 'family='.str_replace(' ', '+', $f).':wght@'.($weights[$f] ?? '400;500;600;700'),
            $families,
        ));

        return "https://fonts.googleapis.com/css2?$q&display=swap";
    }
}
