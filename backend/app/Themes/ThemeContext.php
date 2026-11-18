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

        // Text settings (announcement, footer tagline, perks…) in the shopper's language when translated
        return is_string($value) && $value !== ''
            ? app(\App\I18n\Localization::class)->pick('theme', $this->store->id, "settings.{$path}", $value)
            : $value;
    }

    /** @return array<int, array{id:string,type:string,enabled:bool,settings:array}> */
    public function sections(): array
    {
        // In the editor preview, hidden sections are still skipped — what you see is what gets published
        $l10n = app(\App\I18n\Localization::class);
        $sections = array_values(array_filter($this->config['sections'], fn ($s) => $s['enabled']));
        if (! $l10n->translating()) {
            return $sections;
        }

        return array_map(function ($s) use ($l10n) {
            foreach ($s['settings'] as $key => $value) {
                if (is_string($value) && $value !== '') {
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
            '--font-display' => "'".$this->setting('typography.heading_font')."', 'Noto Serif SC', serif",
            '--font-body' => "'".$this->setting('typography.body_font')."', -apple-system, 'PingFang SC', 'Microsoft YaHei', sans-serif",
        ];

        return implode(' ', array_map(fn ($k, $v) => "$k: $v;", array_keys($vars), $vars));
    }

    public function fontsUrl(): string
    {
        // Google Fonts rejects the whole request if a family lacks a requested weight
        $weights = ['Marcellus' => '400', 'Lato' => '400;700'];
        $families = array_unique([$this->setting('typography.heading_font'), $this->setting('typography.body_font')]);
        $q = implode('&', array_map(
            fn ($f) => 'family='.str_replace(' ', '+', $f).':wght@'.($weights[$f] ?? '400;500;600;700'),
            $families,
        ));

        return "https://fonts.googleapis.com/css2?$q&display=swap";
    }
}
