<?php

namespace App\Themes;

use App\Models\Store;
use App\Models\StoreMedia;
use App\Models\StoreTheme;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ThemeService
{
    /** Valid collection / product ids for the sanitize() call in progress (null = don't check). */
    private ?array $collectionIds = null;

    private ?array $productIds = null;

    /** Where a block may go when its schema doesn't say (the theme's own home blocks). */
    public const DEFAULT_PLACEMENT = ['home', 'page'];

    /** Shown on every block: show it everywhere, or only on computers / phones. */
    public const VISIBILITY_FIELD = ['type' => 'select', 'label' => '显示在', 'options' => ['all' => '电脑和手机', 'desktop' => '只在电脑上', 'mobile' => '只在手机上'], 'default' => 'all'];

    public function schema(string $theme): array
    {
        $schema = config("themes.$theme") ?? throw new InvalidArgumentException("Unknown theme [$theme]");
        foreach ($schema['sections'] as $type => $def) {
            $schema['sections'][$type]['on'] = $def['on'] ?? self::DEFAULT_PLACEMENT;
            $schema['sections'][$type]['fields'] = [...$def['fields'], 'visibility' => self::VISIBILITY_FIELD];
        }
        $schema['templates'] ??= [];

        return $schema;
    }

    /** Fresh config for a theme: every setting at its default, the default home sections and page templates. */
    public function defaults(string $theme): array
    {
        $schema = $this->schema($theme);
        $sections = [];
        foreach ($schema['default_sections'] as $i => $s) {
            $sections[] = $this->newSection($theme, $s['type'], $s['type'].'-'.($i + 1));
        }

        return ['settings' => $this->fieldDefaults($schema['settings']), 'sections' => $sections,
            'templates' => $this->templateDefaults($theme), 'pages' => (object) []];
    }

    /** Default blocks of the product / collection / cart templates (ids prefixed with the template). */
    public function templateDefaults(string $theme): array
    {
        $out = [];
        foreach ($this->schema($theme)['templates'] as $key => $t) {
            if ($key !== 'page') {
                $out[$key] = array_map(fn ($type) => $this->newSection($theme, $type, "$key-$type"), $t['default']);
            }
        }

        return $out;
    }

    /** Starting blocks of a new campaign landing page. */
    public function pageDefaults(string $theme, int $pageId): array
    {
        return array_map(fn ($type) => $this->newSection($theme, $type, "p{$pageId}-$type"), $this->schema($theme)['templates']['page']['default'] ?? ['hero']);
    }

    public function newSection(string $theme, string $type, ?string $id = null): array
    {
        $def = $this->schema($theme)['sections'][$type] ?? throw new InvalidArgumentException("Unknown section [$type]");

        return [
            'id' => $id ?? $type.'-'.Str::lower(Str::random(6)),
            'type' => $type,
            'enabled' => true,
            'settings' => array_map(fn ($f) => $f['default'] ?? null, $def['fields']),
        ];
    }

    /**
     * Clean untrusted editor input against the schema: unknown keys are dropped,
     * every value is coerced/validated, missing values fall back to defaults.
     */
    public function sanitize(string $theme, array $input, bool $checkMedia = true): array
    {
        $schema = $this->schema($theme);
        // Referenced records must belong to the current store (all three models are store-scoped)
        $mediaIds = $checkMedia ? $this->existingMediaIds($input) : null;
        $this->collectionIds = $checkMedia ? \App\Models\Collection::pluck('id')->all() : null;
        $this->productIds = $checkMedia ? $this->existingProductIds($input) : null;

        $settings = [];
        foreach ($schema['settings'] as $group => $def) {
            foreach ($def['fields'] as $key => $field) {
                $settings[$group][$key] = $this->coerce($field, $input['settings'][$group][$key] ?? null, $mediaIds);
            }
        }

        // Block ids are unique across the whole config (market overrides and translations key on them)
        $usedIds = [];
        $sections = $this->sanitizeList($schema, 'home', $input['sections'] ?? [], $mediaIds, $usedIds);

        $templates = [];
        foreach ($schema['templates'] as $key => $t) {
            if ($key === 'page') {
                continue;
            }
            $raw = $input['templates'][$key] ?? null;
            $templates[$key] = is_array($raw) ? $this->sanitizeList($schema, $key, $raw, $mediaIds, $usedIds)
                : array_map(fn ($type) => $this->newSection($theme, $type, $this->freeId("$key-$type", $usedIds)), $t['default']);
        }

        // Campaign landing pages: blocks per page id
        $pages = [];
        foreach ((array) ($input['pages'] ?? []) as $pageId => $raw) {
            if (ctype_digit((string) $pageId) && is_array($raw) && count($pages) < 200) {
                $pages[(string) $pageId] = $this->sanitizeList($schema, 'page', $raw, $mediaIds, $usedIds);
            }
        }

        return ['settings' => $settings, 'sections' => $sections, 'templates' => $templates, 'pages' => (object) $pages];
    }

    /** One list of blocks for a place (home, page, product, collection, cart). */
    private function sanitizeList(array $schema, string $place, array $input, ?array $mediaIds, array &$usedIds): array
    {
        $sections = [];
        $perType = [];
        foreach (array_slice(array_values($input), 0, $schema['max_sections']) as $raw) {
            $type = is_array($raw) ? ($raw['type'] ?? null) : null;
            $def = is_string($type) ? ($schema['sections'][$type] ?? null) : null;
            if (! $def || ! in_array($place, $def['on'], true)) {
                continue;
            }
            $perType[$type] = ($perType[$type] ?? 0) + 1;
            if (isset($def['max_per_page']) && $perType[$type] > $def['max_per_page']) {
                continue;
            }

            $id = is_string($raw['id'] ?? null) && preg_match('/^[a-z0-9_-]{1,40}$/', $raw['id']) && ! isset($usedIds[$raw['id']])
                ? $raw['id'] : $type.'-'.Str::lower(Str::random(6));
            $usedIds[$id] = true;

            $values = [];
            foreach ($def['fields'] as $key => $field) {
                $values[$key] = $this->coerce($field, $raw['settings'][$key] ?? null, $mediaIds);
            }

            $sections[] = ['id' => $id, 'type' => $type, 'enabled' => (bool) ($raw['enabled'] ?? true), 'settings' => $values];
        }

        // A template's own content (product info, the product grid, the bag) is always there and shown
        $fixed = $schema['templates'][$place]['fixed'] ?? null;
        if ($fixed) {
            $at = array_search($fixed, array_column($sections, 'type'), true);
            if ($at === false) {
                $def = $schema['sections'][$fixed];
                $id = $this->freeId("$place-$fixed", $usedIds);
                array_unshift($sections, ['id' => $id, 'type' => $fixed, 'enabled' => true,
                    'settings' => array_map(fn ($f) => $f['default'] ?? null, $def['fields'])]);
            } else {
                $sections[$at]['enabled'] = true;
            }
        }

        return $sections;
    }

    private function freeId(string $id, array &$usedIds): string
    {
        $id = substr($id, 0, 33);
        while (isset($usedIds[$id])) {
            $id = substr($id, 0, 33).'-'.Str::lower(Str::random(4));
        }
        $usedIds[$id] = true;

        return $id;
    }

    /**
     * The config to render for a store: the live theme's published config (or a theme's draft when
     * previewing), else template defaults. $ref = a template key or "r{id}" for one library theme.
     */
    public function resolve(Store $store, bool $preview = false, ?string $ref = null, ?array $proposal = null): ThemeContext
    {
        $row = $ref !== null ? $this->rowForRef($store, $ref) : null;
        if (! $row && ($ref === null || ! config("themes.$ref"))) {
            $row = $this->liveRow($store);
        }
        $theme = $row?->theme && config("themes.{$row->theme}") ? $row->theme
            : ($ref && config("themes.$ref") ? $ref : $this->activeTheme($store));

        $raw = $preview ? ($row?->draft ?? $row?->published) : $row?->published;
        $config = $raw ? $this->sanitize($theme, $raw, checkMedia: false) : $this->defaults($theme);

        // Inside a market: its own design on top (draft while previewing)
        $localized = [];
        $market = app(\App\Markets\MarketContext::class)->activeFor($store->id) ? app(\App\Markets\MarketContext::class)->market() : null;
        if ($proposal && $row && $proposal['row_id'] === $row->id && $proposal['market_id'] === $market?->id) {
            // Previewing an AI proposal: its complete config (already including the market's changes)
            $config = $this->sanitize($theme, $proposal['config'], checkMedia: false);
            $localized = MarketThemes::localizedKeys($proposal['layer'] ?? null);
        } elseif (! $market && ! $preview && $row && $row->theme === $theme && ($variant = app(DesignExperiments::class)->variantFor($row, null)) !== null) {
            // A/B test of the main site's design: visitors in arm B see the tested config
            $config = $this->sanitize($theme, $variant, checkMedia: false);
        } elseif ($market && $row && $row->theme === $theme) {
            $mt = app(MarketThemes::class);
            $m = $mt->row($row, $market);
            $layer = $m ? ($preview ? ($m->draft ?? $m->published) : $m->published) : null;
            // A/B test of this market's design: visitors in arm B see the tested layer
            if (! $preview && ($variant = app(DesignExperiments::class)->variantFor($row, $market)) !== null) {
                $layer = $variant;
            }
            if ($layer) {
                $config = $this->sanitize($theme, $mt->apply($config, $layer), checkMedia: false);
                $localized = MarketThemes::localizedKeys($layer);
            }
        }

        $media = StoreMedia::withoutGlobalScope('store')
            ->where('store_id', $store->id)
            ->whereIn('id', $this->collectImageIds($config))
            ->get()->mapWithKeys(fn ($m) => [$m->id => $m->url()])->all();

        return new ThemeContext($store, $config, $media, $preview, $theme, $localized);
    }

    public function activeTheme(Store $store): string
    {
        if ($store->theme_id && ($row = $this->liveRow($store)) && config("themes.{$row->theme}")) {
            return $row->theme;
        }

        return $store->theme && config("themes.{$store->theme}") ? $store->theme : config('lumee.default_theme');
    }

    /** The library theme that is live: stores.theme_id, else (older stores) the first row of the live template. */
    public function liveRow(Store $store): ?StoreTheme
    {
        $q = fn () => StoreTheme::withoutGlobalScope('store')->where('store_id', $store->id);
        if ($store->theme_id && ($row = $q()->whereKey($store->theme_id)->first())) {
            return $row;
        }
        $key = $store->theme && config("themes.{$store->theme}") ? $store->theme : config('lumee.default_theme');

        return $q()->where('theme', $key)->orderBy('id')->first();
    }

    /** The live theme row, created if missing and pinned in stores.theme_id (call before adding library rows). */
    public function ensureLive(Store $store): StoreTheme
    {
        $row = $this->liveRow($store) ?? StoreTheme::withoutGlobalScope('store')->create([
            'store_id' => $store->id, 'theme' => $this->activeTheme($store), 'source' => 'template',
        ]);
        if ($store->theme_id !== $row->id) {
            $store->forceFill(['theme_id' => $row->id])->save();
        }

        return $row;
    }

    /** "r12" → that library theme of this store; a template key → its live row or first row. */
    public function rowForRef(Store $store, string $ref): ?StoreTheme
    {
        $q = StoreTheme::withoutGlobalScope('store')->where('store_id', $store->id);
        if (preg_match('/^r(\d+)$/', $ref, $m)) {
            return $q->whereKey((int) $m[1])->first();
        }
        if (! config("themes.$ref")) {
            return null;
        }
        $live = $this->liveRow($store);

        return $live && $live->theme === $ref ? $live : $q->where('theme', $ref)->orderBy('id')->first();
    }

    /** Publish a theme's draft and make it the live theme. */
    public function publish(Store $store, StoreTheme $row, ?int $userId = null): void
    {
        $row->published = $row->draft ?? $this->defaults($row->theme);
        $row->draft ??= $row->published;
        $row->published_at = now();
        $row->save();
        app(MarketThemes::class)->snapshot($row, null, $row->published, 'publish', $userId);

        $store->forceFill(['theme' => $row->theme, 'theme_id' => $row->id])->save();
    }

    /** Themes merchants can choose from, with a few colors for the picker. */
    public function catalog(): array
    {
        return collect(config('themes'))->map(fn ($t, $key) => [
            'key' => $key,
            'name' => $t['name'],
            'description' => $t['description'],
            'swatches' => [
                $t['settings']['colors']['fields']['primary']['default'],
                $t['settings']['colors']['fields']['background']['default'],
                $t['settings']['colors']['fields']['text']['default'],
                $t['settings']['colors']['fields']['accent']['default'],
            ],
        ])->values()->all();
    }

    // ── Preview tokens ────────────────────────────────────────────────────────
    // Lets the admin editor iframe show the unpublished draft. Read-only and short-lived.

    /** Token format: {storeId}.{ref}.{expires}.{signature}; ref = template key or "r{id}", signed too. */
    public function previewToken(Store $store, ?string $theme = null, int $ttlSeconds = 7200): string
    {
        $theme ??= $this->activeTheme($store);
        $exp = time() + $ttlSeconds;

        return "{$store->id}.{$theme}.{$exp}.".$this->sign($store->id, $theme, $exp);
    }

    /** @return string|null the theme ref to preview, or null when the token is invalid/expired/for another store */
    public function verifyPreviewToken(Store $store, ?string $token): ?string
    {
        if (! $token || substr_count($token, '.') !== 3) {
            return null;
        }
        [$id, $theme, $exp, $sig] = explode('.', $token);

        $valid = (int) $id === $store->id
            && (int) $exp >= time()
            && (config("themes.$theme") !== null || (preg_match('/^r\d+$/', $theme) && $this->rowForRef($store, $theme)))
            && hash_equals($this->sign((int) $id, $theme, (int) $exp), $sig);

        return $valid ? $theme : null;
    }

    private function sign(int $storeId, string $theme, int $exp): string
    {
        return hash_hmac('sha256', "theme-preview|$storeId|$theme|$exp", (string) config('app.key'));
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function fieldDefaults(array $groups): array
    {
        $out = [];
        foreach ($groups as $group => $def) {
            foreach ($def['fields'] as $key => $field) {
                $out[$group][$key] = $field['default'] ?? null;
            }
        }

        return $out;
    }

    private function coerce(array $field, mixed $value, ?array $mediaIds): mixed
    {
        $default = $field['default'] ?? null;

        switch ($field['type']) {
            case 'text':
            case 'textarea':
                if (! is_string($value)) {
                    return $default ?? '';
                }
                // Strip control chars (keep newlines for textarea); output is always HTML-escaped on render
                $value = preg_replace($field['type'] === 'textarea' ? '/[^\P{C}\n]/u' : '/\p{C}/u', '', $value) ?? '';

                return Str::limit(trim($value), $field['max'] ?? ($field['type'] === 'textarea' ? 2000 : 255), '');

            case 'url':
                if (! is_string($value) || ($value = trim($value)) === '') {
                    return $default ?? '';
                }
                // Only relative paths, anchors and http(s) — never javascript:, data:, etc.
                $ok = preg_match('~^(/(?!/)|#|https?://)~i', $value) === 1 && ! preg_match('/[\s<>"\']/', $value);

                return $ok ? Str::limit($value, 500, '') : ($default ?? '');

            case 'color':
                return \App\Support\Color::isHex($value) ? strtoupper($value) : $default;

            case 'select':
                return is_scalar($value) && array_key_exists((string) $value, $field['options']) ? (string) $value : $default;

            case 'range':
                if (! is_numeric($value)) {
                    return $default;
                }

                return max($field['min'], min($field['max'], (int) $value));

            case 'checkbox':
                return $value === null ? (bool) $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);

            case 'image':
                if (! is_numeric($value)) {
                    return null;
                }
                $id = (int) $value;

                return $mediaIds === null || in_array($id, $mediaIds, true) ? $id : null;

            case 'collection':
                if (! is_numeric($value)) {
                    return null;
                }
                $id = (int) $value;

                return $this->collectionIds === null || in_array($id, $this->collectionIds, true) ? $id : null;

            case 'product':
                if (! is_numeric($value)) {
                    return null;
                }
                $id = (int) $value;

                return $this->productIds === null || in_array($id, $this->productIds, true) ? $id : null;

            case 'datetime':
                // "2026-11-28T23:59" (store time zone); anything else → not set
                return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value) && strtotime($value) !== false ? $value : null;
        }

        return $default;
    }

    /** Product ids referenced by "product" fields that belong to the current store. */
    private function existingProductIds(array $input): array
    {
        $ids = [];
        array_walk_recursive($input, function ($v, $k) use (&$ids) {
            if (is_numeric($v) && is_string($k) && str_ends_with($k, '_product')) {
                $ids[] = (int) $v;
            }
        });

        return $ids ? \App\Models\Product::whereIn('id', array_unique($ids))->pluck('id')->all() : [];
    }

    /** Media ids referenced in raw input that actually belong to the current store. */
    private function existingMediaIds(array $input): array
    {
        $ids = [];
        array_walk_recursive($input, function ($v, $k) use (&$ids) {
            if (is_numeric($v) && is_string($k)) {
                $ids[] = (int) $v;
            }
        });

        // StoreMedia is store-scoped, so ids from other stores are simply not found
        return $ids ? StoreMedia::whereIn('id', array_unique($ids))->pluck('id')->all() : [];
    }

    private function collectImageIds(array $config): array
    {
        $ids = [];
        foreach ($config['settings'] as $group) {
            foreach ($group as $v) {
                // logo is the only image setting today; any int could be an id, filtered by the query
                if (is_int($v)) {
                    $ids[] = $v;
                }
            }
        }
        foreach (self::allSections($config) as $s) {
            foreach ($s['settings'] as $k => $v) {
                if (str_contains($k, 'image') && is_int($v)) {   // image, slide1_image, slide1_mobile_image…
                    $ids[] = $v;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /** Every block in a config: home, page templates and landing pages. */
    public static function allSections(array $config): array
    {
        $all = $config['sections'] ?? [];
        foreach ((array) ($config['templates'] ?? []) as $list) {
            $all = [...$all, ...(array) $list];
        }
        foreach ((array) ($config['pages'] ?? []) as $list) {
            $all = [...$all, ...(array) $list];
        }

        return $all;
    }
}
