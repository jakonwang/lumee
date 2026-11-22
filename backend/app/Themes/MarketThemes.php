<?php

namespace App\Themes;

use App\Models\Market;
use App\Models\StoreTheme;
use App\Models\StoreThemeMarket;
use App\Models\ThemeVersion;

/**
 * Store design per market. A market layer sits on top of a library theme:
 *   settings           — theme settings this market changes ({group: {field: value}})
 *   section_overrides  — block fields it changes ({block id: {field: value, enabled?}}), on the home page,
 *                        the product / collection / cart templates and landing pages (block ids are unique)
 *   sections           — null = the home page follows the main site; a list = the market's own home page
 *
 * The editor always works on a complete config. In a market it sends the whole config back and
 * the layer keeps only what differs from the main site (diff()), so everything the market didn't
 * change keeps following the main site — including later edits there.
 */
class MarketThemes
{
    public function __construct(private ThemeService $themes) {}

    public static function empty(): array
    {
        return ['settings' => [], 'section_overrides' => [], 'sections' => null];
    }

    /** Main-site config + market layer → the config the market renders. */
    public function apply(array $base, ?array $layer): array
    {
        if (! $layer) {
            return $base;
        }
        foreach ((array) ($layer['settings'] ?? []) as $group => $fields) {
            foreach ((array) $fields as $key => $value) {
                if (array_key_exists($group, $base['settings']) && array_key_exists($key, $base['settings'][$group])) {
                    $base['settings'][$group][$key] = $value;
                }
            }
        }
        $overrides = (array) ($layer['section_overrides'] ?? []);
        $patch = function (array $list) use ($overrides): array {
            foreach ($list as $i => $s) {
                foreach ((array) ($overrides[$s['id']] ?? []) as $key => $value) {
                    if ($key === 'enabled') {
                        $list[$i]['enabled'] = (bool) $value;
                    } elseif (array_key_exists($key, $s['settings'])) {
                        $list[$i]['settings'][$key] = $value;
                    }
                }
            }

            return $list;
        };
        $base['sections'] = is_array($layer['sections'] ?? null) ? $layer['sections'] : $patch($base['sections']);
        foreach ((array) ($base['templates'] ?? []) as $key => $list) {
            $base['templates'][$key] = $patch((array) $list);
        }
        $pages = (array) ($base['pages'] ?? []);
        foreach ($pages as $id => $list) {
            $pages[$id] = $patch((array) $list);
        }
        $base['pages'] = (object) $pages;

        return $base;
    }

    /** What a market changes, given the main-site config and the (sanitized) config the market should show. */
    public function diff(array $base, array $effective, bool $ownSections): array
    {
        $layer = self::empty();
        foreach ($effective['settings'] as $group => $fields) {
            foreach ($fields as $key => $value) {
                if (($base['settings'][$group][$key] ?? null) !== $value) {
                    $layer['settings'][$group][$key] = $value;
                }
            }
        }
        // Structure follows the main site (except an own home page); only field values and hiding are the market's
        $baseBlocks = $ownSections ? [] : $base['sections'];
        foreach ([...array_values((array) ($base['templates'] ?? [])), ...array_values((array) ($base['pages'] ?? []))] as $list) {
            $baseBlocks = [...$baseBlocks, ...(array) $list];
        }
        if ($ownSections) {
            $layer['sections'] = $effective['sections'];
        }
        $mine = collect(ThemeService::allSections($effective))->keyBy('id');
        foreach ($baseBlocks as $s) {
            $e = $mine->get($s['id']);
            if (! $e) {
                continue;
            }
            $o = [];
            foreach ($s['settings'] as $key => $value) {
                if (array_key_exists($key, $e['settings']) && $e['settings'][$key] !== $value) {
                    $o[$key] = $e['settings'][$key];
                }
            }
            if ($e['enabled'] !== $s['enabled']) {
                $o['enabled'] = $e['enabled'];
            }
            if ($o) {
                $layer['section_overrides'][$s['id']] = $o;
            }
        }

        return $layer;
    }

    /**
     * Which values came from the market (they're already in its language, so not translated):
     * "settings.{group}.{field}", "sections.{id}.{field}", or "sections.*" for an own home page.
     *
     * @return array<string, true>
     */
    public static function localizedKeys(?array $layer): array
    {
        if (! $layer) {
            return [];
        }
        $keys = [];
        foreach ((array) ($layer['settings'] ?? []) as $group => $fields) {
            foreach (array_keys((array) $fields) as $key) {
                $keys["settings.$group.$key"] = true;
            }
        }
        if (is_array($layer['sections'] ?? null)) {
            $keys['sections.*'] = true;
        } else {
            foreach ((array) ($layer['section_overrides'] ?? []) as $id => $fields) {
                foreach (array_keys((array) $fields) as $key) {
                    $keys["sections.$id.$key"] = true;
                }
            }
        }

        return $keys;
    }

    public function row(StoreTheme $theme, Market $market): ?StoreThemeMarket
    {
        return StoreThemeMarket::withoutGlobalScope('store')->where('store_theme_id', $theme->id)->where('market_id', $market->id)->first();
    }

    public function rowOrNew(StoreTheme $theme, Market $market): StoreThemeMarket
    {
        return $this->row($theme, $market) ?? (new StoreThemeMarket(['store_theme_id' => $theme->id, 'market_id' => $market->id]))
            ->forceFill(['store_id' => $theme->store_id]);
    }

    /** The main site's config of a library theme (draft or published). */
    public function base(StoreTheme $theme, bool $draft): array
    {
        $raw = $draft ? ($theme->draft ?? $theme->published) : $theme->published;

        // Always through sanitize(), so untouched fields compare equal ("" vs null defaults)
        return $this->themes->sanitize($theme->theme, $raw ?? $this->themes->defaults($theme->theme), checkMedia: false);
    }

    /** Config the editor shows for a market (draft), and whether its home page is its own. */
    public function effective(StoreTheme $theme, Market $market, bool $draft = true): array
    {
        $row = $this->row($theme, $market);
        $layer = $row ? ($draft ? ($row->draft ?? $row->published) : $row->published) : null;

        return $this->apply($this->base($theme, $draft), $layer);
    }

    /** Save the market's draft from a complete config (already sanitized). */
    public function saveDraft(StoreTheme $theme, Market $market, array $effective, bool $ownSections): StoreThemeMarket
    {
        $row = $this->rowOrNew($theme, $market);
        $row->draft = $this->diff($this->base($theme, true), $effective, $ownSections);
        $row->save();

        return $row;
    }

    public function publish(StoreTheme $theme, Market $market, ?int $userId = null): StoreThemeMarket
    {
        $row = $this->rowOrNew($theme, $market);
        $row->published = $row->draft ?? self::empty();
        $row->draft ??= $row->published;
        $row->published_at = now();
        $row->save();
        $this->snapshot($theme, $market, $row->published, 'publish', $userId);

        return $row;
    }

    public function discard(StoreTheme $theme, Market $market): StoreThemeMarket
    {
        $row = $this->rowOrNew($theme, $market);
        $row->draft = $row->published;
        $row->save();

        return $row;
    }

    /**
     * Change a few settings of a market on the live design right away (draft and published),
     * e.g. a regional preset's announcement and perks.
     *
     * @param  array<string, mixed>  $values  "group.field" => value
     */
    public function setLive(StoreTheme $theme, Market $market, array $values): void
    {
        $row = $this->rowOrNew($theme, $market);
        foreach (['draft', 'published'] as $col) {
            $layer = $row->{$col} ?? self::empty();
            foreach ($values as $path => $value) {
                [$group, $key] = explode('.', $path, 2);
                $layer['settings'][$group][$key] = $value;
            }
            $row->{$col} = $layer;
        }
        $row->published_at ??= now();
        $row->save();
    }

    /** Keep a copy of what was published; the newest 30 per theme and market are kept. */
    public function snapshot(StoreTheme $theme, ?Market $market, array $config, string $source, ?int $userId = null, ?string $note = null): void
    {
        $v = new ThemeVersion(['store_theme_id' => $theme->id, 'market_id' => $market?->id, 'config' => $config, 'source' => $source, 'note' => $note, 'created_by' => $userId]);
        $v->forceFill(['store_id' => $theme->store_id])->save();
        $q = fn () => ThemeVersion::withoutGlobalScope('store')->where('store_theme_id', $theme->id)->where('market_id', $market?->id);
        $keep = $q()->orderByDesc('id')->limit(30)->pluck('id');
        $q()->whereNotIn('id', $keep)->delete();
    }

    /** Markets that have a layer on this theme (customized), by id. @return array<int, StoreThemeMarket> */
    public function layers(StoreTheme $theme): array
    {
        return StoreThemeMarket::withoutGlobalScope('store')->where('store_theme_id', $theme->id)->get()->keyBy('market_id')->all();
    }

    public static function isCustomized(?StoreThemeMarket $row): bool
    {
        $l = $row?->published ?? $row?->draft;

        return $l && (! empty($l['settings']) || ! empty($l['section_overrides']) || is_array($l['sections'] ?? null));
    }
}
