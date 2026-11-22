<?php

namespace App\Themes;

use App\Content\BrandKit;
use App\Content\SceneImages;
use App\LeadFinder\LeadAi;
use App\Markets\MarketContext;
use App\Markets\ShoppingCalendar;
use App\Models\Collection;
use App\Models\Discount;
use App\Models\Market;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreTheme;
use App\Models\ThemeVersion;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * AI store design (phase D of docs/STOREFRONT-DESIGN-PLAN.md), with Claude through LeadAi::json.
 *
 *  - propose(): a one-sentence brief ("Mother's Day home page for Brazil, warm, necklaces first") or an
 *    assistant request ("make the top more promotional") → a proposal for one page of the design.
 *    Claude answers with block types, field values and a style kit from the theme's schema — never HTML
 *    or CSS — and may only pick products, collections and discount codes from the candidate lists it is
 *    given. Everything goes through ThemeService::sanitize() and the design check.
 *  - Proposals are kept 30 minutes for a preview (?_proposal=…) and applied to the draft only when the
 *    merchant says so; the draft before is saved as a version, so it can be restored. Nothing is published.
 *  - review(): the design check plus Claude's design advice for the page.
 *  - localize(): a market's version of another market's home page, rewritten for its language and shoppers.
 *  - A monthly allowance of AI requests per store (settings.design.ai_quota, default 100).
 */
class AiDesigner
{
    public const DEFAULT_QUOTA = 100;

    private const LANG = ['en' => 'English', 'es' => 'Spanish (Latin American)', 'pt' => 'Portuguese (Brazilian)', 'fr' => 'French', 'de' => 'German',
        'it' => 'Italian', 'ja' => 'Japanese', 'ar' => 'Arabic', 'zh_CN' => 'Simplified Chinese', 'zh' => 'Simplified Chinese'];

    public function __construct(
        private ThemeService $themes,
        private MarketThemes $markets,
        private AutoDesigner $auto,
        private DesignCheck $check,
        private LeadAi $ai,
        private BannerComposer $banners,
    ) {}

    // ── allowance ────────────────────────────────────────────────────────────

    /** @return array{ready: bool, used: int, quota: int, images: bool} */
    public static function status(Store $store): array
    {
        $d = (array) ($store->settings['design'] ?? []);
        $u = (array) ($d['ai_usage'] ?? []);

        return [
            'ready' => LeadAi::ready($store),
            'used' => ($u['month'] ?? null) === now()->format('Y-m') ? (int) ($u['count'] ?? 0) : 0,
            'quota' => (int) ($d['ai_quota'] ?? self::DEFAULT_QUOTA),
            'images' => SceneImages::for($store)->status()['connected'],
        ];
    }

    private function charge(Store $store): void
    {
        $s = self::status($store);
        if (! $s['ready']) {
            throw new RuntimeException(__('先在「获客雷达 › 设置」填写 Claude API Key'));
        }
        if ($s['used'] >= $s['quota']) {
            throw new RuntimeException(__('本月的 AI 装修次数（:p0 次）已用完', ['p0' => $s['quota']]));
        }
        $store->refresh();
        $settings = $store->settings ?? [];
        $settings['design']['ai_usage'] = ['month' => now()->format('Y-m'), 'count' => $s['used'] + 1];
        $store->forceFill(['settings' => $settings])->save();
    }

    // ── proposals ────────────────────────────────────────────────────────────

    /**
     * @param  string  $place  home | product | collection | cart | page:{id}
     * @param  string  $mode  build (a new page from a brief) | edit (change what's there)
     * @return array{token: string, reply: string, summary: string[], changes: string[], issues: array, place: string}
     */
    public function propose(Store $store, StoreTheme $row, ?Market $market, string $place, string $request, string $mode, bool $aiBanner = false): array
    {
        $this->charge($store);

        return app(MarketContext::class)->within($market, function () use ($store, $row, $market, $place, $request, $mode, $aiBanner) {
            $schema = $this->themes->schema($row->theme);
            $config = $this->current($row, $market);
            $list = $this->listOf($config, $place, $row->theme);
            $kind = str_starts_with($place, 'page:') ? 'page' : $place;
            $ownHome = $market && is_array(($this->markets->row($row, $market)?->draft ?? [])['sections'] ?? null);
            $locked = $market && $kind !== 'home';   // markets follow the main site's structure (except an own home page)
            $cand = $this->candidates($store, $market);

            $types = array_keys(array_filter($schema['sections'], fn ($d) => in_array($kind, $d['on'], true)));
            $answer = $this->ai->json($store, $this->prompt($store, $market, $schema, $config, $list, $place, $kind, $cand, $request, $mode, $locked),
                $this->schemaFor($types, array_keys($schema['kits'] ?? [])), 'medium', 8000);

            $next = $this->merge($schema, $row->theme, $config, $list, $place, $answer, $cand, $locked);
            if ($aiBanner && $kind !== 'product' && $kind !== 'cart') {
                $this->banner($store, $next, $place, $cand['products'], $request);
            }
            $next = $this->themes->sanitize($row->theme, $next);
            $own = $market && $kind === 'home' ? ($ownHome || $this->structureChanged($list, $next['sections'])) : false;
            $layer = $market ? $this->markets->diff($this->markets->base($row, true), $next, $own) : null;

            $token = Str::random(40);
            Cache::put("theme-proposal:$token", ['store_id' => $store->id, 'row_id' => $row->id, 'market_id' => $market?->id, 'place' => $place,
                'config' => $next, 'own' => $own, 'layer' => $layer, 'request' => mb_substr($request, 0, 300)], now()->addMinutes(30));

            return [
                'token' => $token, 'place' => $place,
                'reply' => (string) ($answer['reply'] ?? ''),
                'summary' => array_values(array_filter(array_map('strval', (array) ($answer['summary'] ?? [])))),
                'changes' => $this->changes($schema, $config, $next, $place),
                'issues' => $this->check->run($store, $row, $market, $next, $layer),
            ];
        });
    }

    /** A proposal kept for preview / apply (null when expired or another store's). */
    public static function proposal(Store $store, ?string $token): ?array
    {
        $p = $token && preg_match('/^[A-Za-z0-9]{40}$/', $token) ? Cache::get("theme-proposal:$token") : null;

        return $p && $p['store_id'] === $store->id ? $p : null;
    }

    /** Put a proposal into the draft; the draft before becomes a version (restorable). */
    public function apply(Store $store, string $token, ?int $userId = null): array
    {
        $p = self::proposal($store, $token) ?? throw new RuntimeException(__('这个方案已经过期，请重新生成'));
        $row = StoreTheme::findOrFail($p['row_id']);
        $market = $p['market_id'] ? Market::findOrFail($p['market_id']) : null;
        $before = $market ? ($this->markets->row($row, $market)?->draft ?? MarketThemes::empty()) : ($row->draft ?? $row->published ?? $this->themes->defaults($row->theme));
        $this->markets->snapshot($row, $market, $before, 'ai', $userId, mb_substr(__('AI 修改前：:p0', ['p0' => $p['request']]), 0, 200));
        if ($market) {
            $this->markets->saveDraft($row, $market, $p['config'], $p['own']);
        } else {
            $row->draft = $p['config'];
            $row->save();
        }
        Cache::forget("theme-proposal:$token");

        return [$row, $market];
    }

    // ── review & localize ────────────────────────────────────────────────────

    /** @return array{issues: array, suggestions: array<int, array{title: string, detail: string, block_id: string}>} */
    public function review(Store $store, StoreTheme $row, ?Market $market, string $place): array
    {
        $this->charge($store);

        return app(MarketContext::class)->within($market, function () use ($store, $row, $market, $place) {
            $schema = $this->themes->schema($row->theme);
            $config = $this->current($row, $market);
            $issues = $this->check->run($store, $row, $market);
            $prompt = implode("\n\n", [
                'You are a senior e-commerce designer reviewing one page of an online store. Give 3 to 6 concrete, prioritised suggestions that would raise conversion for THIS market\'s shoppers: hierarchy, what\'s above the fold on phones, trust, local habits, copy clarity, product choice. Refer to blocks by their id. Do not repeat the automatic check results below; build on them.',
                'Write title and detail in '.$this->adminLanguage().'. Keep each detail under 60 words.',
                $this->marketBrief($store, $market),
                "Page: $place\nStyle settings: ".json_encode($config['settings']['style'] ?? [], JSON_UNESCAPED_UNICODE),
                "Blocks:\n".$this->describeBlocks($schema, $this->listOf($config, $place, $row->theme)),
                "Automatic check results:\n".(collect($issues)->map(fn ($i) => "- [{$i['level']}] {$i['message']}")->implode("\n") ?: '- none'),
            ]);
            $r = $this->ai->json($store, $prompt, ['type' => 'object', 'additionalProperties' => false, 'required' => ['suggestions'], 'properties' => [
                'suggestions' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['title', 'detail', 'block_id'],
                    'properties' => ['title' => ['type' => 'string'], 'detail' => ['type' => 'string'], 'block_id' => ['type' => 'string']]]],
            ]], 'medium', 4000);

            return ['issues' => $issues, 'suggestions' => array_slice(array_values(array_filter((array) ($r['suggestions'] ?? []), 'is_array')), 0, 8)];
        });
    }

    /**
     * The target market's own home page, rewritten from another market's (null = the main site) for the target's
     * language and shoppers; saved as the target's draft (the draft before kept as a version).
     *
     * @return string[] what was done (merchant language)
     */
    public function localize(Store $store, StoreTheme $row, ?Market $from, Market $to, ?int $userId = null): array
    {
        $this->charge($store);
        $schema = $this->themes->schema($row->theme);
        $source = $from ? $this->markets->effective($row, $from) : $this->markets->base($row, true);
        $home = $source['sections'];

        return app(MarketContext::class)->within($to, function () use ($store, $row, $schema, $source, $home, $from, $to, $userId) {
            $lang = DesignCopy::lang($to->default_locale);
            $facts = $this->auto->facts($store, $to, $lang, new SectionData());
            $texts = [];
            foreach ($home as $s) {
                foreach ($schema['sections'][$s['type']]['fields'] ?? [] as $k => $f) {
                    if (in_array($f['type'], ['text', 'textarea'], true) && trim((string) $s['settings'][$k]) !== '' && ! preg_match('/^code\d$/', $k)) {
                        $texts[] = ['id' => $s['id'], 'key' => $k, 'text' => (string) $s['settings'][$k], 'max' => $f['max'] ?? 255];
                    }
                }
            }
            foreach (['header.announcement_text', 'product.perks', 'footer.tagline'] as $path) {
                [$g, $k] = explode('.', $path);
                if (trim((string) ($source['settings'][$g][$k] ?? '')) !== '') {
                    $texts[] = ['id' => 'settings', 'key' => $path, 'text' => (string) $source['settings'][$g][$k], 'max' => $schema['settings'][$g]['fields'][$k]['max'] ?? 255];
                }
            }
            $prompt = implode("\n\n", [
                'Rewrite an online store\'s home page texts for another country. Do not translate word for word: write what a local copywriter would write for shoppers in '.$this->marketLine($to).'. Keep each text\'s purpose and stay within its max length. Never invent prices, thresholds, days or payment methods: when a text mentions shipping, returns, installments, payment or duties, use these exact local facts instead: '.implode(' | ', $facts).'. Return every item with the same id and key.',
                'Language: '.(self::LANG[$to->default_locale] ?? $to->default_locale).'. Also write "summary" in '.$this->adminLanguage().'.',
                $this->brandBrief($store),
                'Texts (JSON): '.json_encode($texts, JSON_UNESCAPED_UNICODE),
            ]);
            $r = $this->ai->json($store, $prompt, ['type' => 'object', 'additionalProperties' => false, 'required' => ['items', 'summary'], 'properties' => [
                'items' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'key', 'text'],
                    'properties' => ['id' => ['type' => 'string'], 'key' => ['type' => 'string'], 'text' => ['type' => 'string']]]],
                'summary' => ['type' => 'array', 'items' => ['type' => 'string']],
            ]], 'medium', 8000);

            $target = $this->markets->effective($row, $to);
            $byId = collect($home)->keyBy('id')->all();
            foreach ((array) ($r['items'] ?? []) as $it) {
                $id = (string) ($it['id'] ?? '');
                $key = (string) ($it['key'] ?? '');
                $text = (string) ($it['text'] ?? '');
                if ($id === 'settings' && in_array($key, ['header.announcement_text', 'product.perks', 'footer.tagline'], true)) {
                    [$g, $k] = explode('.', $key);
                    $target['settings'][$g][$k] = $text;
                } elseif (isset($byId[$id]) && array_key_exists($key, $byId[$id]['settings'])
                    && in_array($schema['sections'][$byId[$id]['type']]['fields'][$key]['type'] ?? null, ['text', 'textarea'], true)) {
                    $byId[$id]['settings'][$key] = $text;
                }
            }
            $target['settings']['header']['announcement_enabled'] = (bool) ($source['settings']['header']['announcement_enabled'] ?? true);
            $target['sections'] = array_values($byId);
            $target = $this->themes->sanitize($row->theme, $target);

            $before = $this->markets->row($row, $to)?->draft ?? MarketThemes::empty();
            $this->markets->snapshot($row, $to, $before, 'ai', $userId, mb_substr(__('AI 改写前（从「:p0」改写）', ['p0' => $from?->name ?? __('主站点')]), 0, 200));
            $this->markets->saveDraft($row, $to, $target, true);

            return array_values(array_filter(array_map('strval', (array) ($r['summary'] ?? []))));
        });
    }

    // ── building blocks ──────────────────────────────────────────────────────

    private function current(StoreTheme $row, ?Market $market): array
    {
        return $market ? $this->markets->effective($row, $market) : $this->markets->base($row, true);
    }

    private function listOf(array $config, string $place, string $theme): array
    {
        if ($place === 'home') {
            return $config['sections'];
        }
        if (str_starts_with($place, 'page:')) {
            $id = (int) substr($place, 5);
            $pages = (array) ($config['pages'] ?? []);
            if (isset($pages[(string) $id])) {
                return (array) $pages[(string) $id];
            }

            return $theme !== '' ? $this->themes->pageDefaults($theme, $id) : [];   // not designed yet: its default blocks
        }

        return (array) ($config['templates'][$place] ?? []);
    }

    private function setList(array $config, string $place, array $list): array
    {
        if ($place === 'home') {
            $config['sections'] = $list;
        } elseif (str_starts_with($place, 'page:')) {
            $pages = (array) ($config['pages'] ?? []);
            $pages[substr($place, 5)] = $list;
            $config['pages'] = $pages;
        } else {
            $config['templates'][$place] = $list;
        }

        return $config;
    }

    /** What the AI may pick from: products, collections, discount codes (ids / codes are checked again after). */
    private function candidates(Store $store, ?Market $market): array
    {
        $data = new SectionData();
        $best = $data->bestsellers(30, 20)->pluck('id')->all();
        $products = Product::active()->with(SectionData::VARIANTS)->orderByRaw($best ? 'CASE WHEN id IN ('.implode(',', array_map('intval', $best)).') THEN 0 ELSE 1 END' : '1')
            ->latest('id')->limit(40)->get();
        $currency = $store->currency;

        return [
            'products' => $products,
            'product_lines' => $products->map(fn ($p) => [
                'id' => $p->id, 'title' => Str::limit($p->title, 70), 'handle' => $p->handle,
                'price' => Money::display((int) $p->variants->min('price'), $currency),
                'on_sale' => $p->variants->contains(fn ($v) => $v->compare_at_price > $v->price),
                'bestseller' => in_array($p->id, $best, true),
            ])->values()->all(),
            'collections' => Collection::published()->withCount(['products' => fn ($q) => $q->active()])->orderByDesc('products_count')->limit(30)->get(['id', 'title', 'handle'])
                ->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'handle' => $c->handle, 'products' => $c->products_count])->values()->all(),
            'codes' => Discount::where('active', true)->get()->filter(fn ($d) => $d->status() === 'active')->take(10)
                ->map(fn ($d) => ['code' => $d->code, 'gives' => $d->label($currency)])->values()->all(),
            'events' => array_slice(ShoppingCalendar::upcoming($market ? (array) $market->countries : []), 0, 4),
            'photos' => count($data->ugc(12, 4)),
            'facts' => $this->auto->facts($store, $market, DesignCopy::lang($market?->default_locale ?? \App\I18n\Localization::storeDefault($store)), $data),
        ];
    }

    private function prompt(Store $store, ?Market $market, array $schema, array $config, array $list, string $place, string $kind, array $cand, string $request, string $mode, bool $locked): string
    {
        $allowed = array_filter($schema['sections'], fn ($d) => in_array($kind, $d['on'], true));
        $catalogue = collect($allowed)->map(function ($d, $type) {
            $fields = collect($d['fields'])->map(fn ($f, $k) => match ($f['type']) {
                'image' => "$k: image (set by the merchant — leave it out)",
                'select' => "$k: one of ".implode('|', array_keys($f['options'])),
                'range' => "$k: number {$f['min']}–{$f['max']}",
                'checkbox' => "$k: true|false",
                'collection' => "$k: collection id from the list",
                'product' => "$k: product id from the list",
                'datetime' => "$k: YYYY-MM-DDTHH:MM (store time) or empty",
                'url' => "$k: link such as /collections/{handle}, /products/{handle}, /collections/all",
                default => "$k: {$f['type']}".(isset($f['max']) ? " (max {$f['max']} chars)" : ''),
            })->implode('; ');

            return "- $type ({$d['label']}".(isset($d['max_per_page']) ? ', at most '.$d['max_per_page'] : '')."): $fields";
        })->implode("\n");
        $fixed = $schema['templates'][$kind]['fixed'] ?? null;
        $kits = collect($schema['kits'] ?? [])->map(fn ($k, $key) => "$key ({$k['name']})")->implode(', ');
        $lang = $market?->default_locale ?? \App\I18n\Localization::storeDefault($store);

        $rules = [
            'You design one page of an online store by choosing blocks and filling in their fields. You never write HTML or CSS.',
            $mode === 'build'
                ? 'Build this page from the brief: pick 4–8 blocks in a strong order (phones first: products early), fill every field, and write all shopper-facing text yourself.'
                : 'Change the page as the merchant asks and keep everything else as it is.',
            'Return "sections" as the complete list of blocks for this page, in order. For a block you keep, use its id and include only the settings you change. For a new block, use an empty id and fill its fields.',
            $locked ? 'IMPORTANT: this market follows the main site\'s page structure: keep exactly the same blocks (same ids, same order, nothing added or removed). Only change field values or enabled.' : '',
            $fixed ? "The block \"$fixed\" is the page's own content: keep it." : '',
            'Products, collections and discount codes: use ONLY ids / handles / codes from the lists below. Never invent products, prices, numbers or promises. When you mention shipping, returns, installments, payment or duties, use these exact facts: '.implode(' | ', $cand['facts']).'.',
            'Shopper-facing text: write in '.(self::LANG[$lang] ?? $lang).' as a local copywriter would (not a literal translation), short and specific. Follow the brand voice; never use the banned words.',
            '"settings": theme settings to change, as path "group.field" with a string value (colours as #RRGGBB). "kit": a style kit key to apply first, or "" for none. Prefer keeping the brand primary colour unless asked.',
            'Write "reply" (one or two sentences to the merchant) and "summary" (what you changed, short items) in '.$this->adminLanguage().'.',
        ];

        return implode("\n\n", array_filter([
            implode("\n", array_filter($rules)),
            $this->brandBrief($store),
            $this->marketBrief($store, $market),
            'Upcoming shopping events: '.(collect($cand['events'])->map(fn ($e) => __($e['name'])." ({$e['key']}, {$e['start']} – {$e['end']})")->implode('; ') ?: 'none'),
            'Products (id, title, price, sale, best seller): '.json_encode($cand['product_lines'], JSON_UNESCAPED_UNICODE),
            'Collections: '.json_encode($cand['collections'], JSON_UNESCAPED_UNICODE),
            'Active discount codes (the only codes allowed in coupon_bar): '.json_encode($cand['codes'], JSON_UNESCAPED_UNICODE),
            'Customer photos available for ugc_wall: '.$cand['photos'],
            "Blocks you can use on this page ($kind):\n$catalogue",
            'Style kits: '.($kits ?: 'none').'. Current style: '.json_encode(['colors' => $config['settings']['colors'] ?? [], 'typography' => $config['settings']['typography'] ?? [], 'style' => $config['settings']['style'] ?? []], JSON_UNESCAPED_UNICODE),
            "Current page ($place):\n".($list ? $this->describeBlocks($schema, $list) : '(empty)'),
            ($mode === 'build' ? 'Brief: ' : 'Merchant\'s request: ').$request,
        ]));
    }

    private function brandBrief(Store $store): string
    {
        $k = BrandKit::for($store)->all();

        return 'Store: '.$store->name."\nBrand positioning: ".($k['positioning'] ?: '—')."\nAudience: ".($k['audience'] ?: '—')."\nVoice: ".($k['voice'] ?: '—')
            ."\nBanned words: ".(implode(', ', $k['banned_words']) ?: '—');
    }

    private function marketBrief(Store $store, ?Market $market): string
    {
        return $market ? 'Market: '.$this->marketLine($market).'. Region: '.AutoDesigner::regionName(AutoDesigner::region($market, $store)).'.'
            : 'Market: the main site (all countries without their own site), currency '.$store->baseCurrency().'.';
    }

    private function marketLine(Market $m): string
    {
        return $m->name.' ('.implode(', ', (array) $m->countries).'), currency '.$m->currency.', language '.(self::LANG[$m->default_locale] ?? $m->default_locale);
    }

    private function adminLanguage(): string
    {
        return str_starts_with(app()->getLocale(), 'zh') ? 'Simplified Chinese' : 'English';
    }

    /** Compact view of blocks for a prompt: id, type, shown or hidden, the values that matter. */
    private function describeBlocks(array $schema, array $list): string
    {
        return collect($list)->map(function ($s) use ($schema) {
            $vals = collect($s['settings'])->filter(fn ($v, $k) => $v !== null && $v !== '' && ($schema['sections'][$s['type']]['fields'][$k]['type'] ?? '') !== 'image')
                ->map(fn ($v) => is_string($v) ? Str::limit($v, 120) : $v)->all();

            return "- id={$s['id']} type={$s['type']}".($s['enabled'] ? '' : ' (hidden)').' '.json_encode($vals, JSON_UNESCAPED_UNICODE);
        })->implode("\n");
    }

    private function schemaFor(array $types, array $kits): array
    {
        $pairs = fn (string $k) => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => [$k, 'value'],
            'properties' => [$k => ['type' => 'string'], 'value' => ['type' => 'string']]]];

        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['reply', 'summary', 'kit', 'settings', 'sections'], 'properties' => [
            'reply' => ['type' => 'string'],
            'summary' => ['type' => 'array', 'items' => ['type' => 'string']],
            'kit' => ['type' => 'string', 'enum' => ['', ...$kits]],
            'settings' => $pairs('path'),
            'sections' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'type', 'enabled', 'settings'],
                'properties' => ['id' => ['type' => 'string'], 'type' => ['type' => 'string', 'enum' => array_values($types)], 'enabled' => ['type' => 'boolean'], 'settings' => $pairs('key')]]],
        ]];
    }

    /** Claude's answer → a config. Only fields the schema has; ids, handles and codes only from the candidates; images never. */
    private function merge(array $schema, string $theme, array $config, array $list, string $place, array $answer, array $cand, bool $locked): array
    {
        // Style kit, then individual settings
        $kit = (string) ($answer['kit'] ?? '');
        foreach ($schema['kits'][$kit]['settings'] ?? [] as $path => $want) {
            [$g, $k] = explode('.', $path, 2);
            $field = $schema['settings'][$g]['fields'][$k] ?? null;
            $value = $field && $field['type'] === 'select' ? collect((array) $want)->first(fn ($v) => array_key_exists($v, $field['options'])) : (is_array($want) ? $want[0] : $want);
            if ($field && $value !== null) {
                $config['settings'][$g][$k] = $value;
            }
        }
        foreach ((array) ($answer['settings'] ?? []) as $pair) {
            [$g, $k] = array_pad(explode('.', (string) ($pair['path'] ?? ''), 2), 2, '');
            $field = $schema['settings'][$g]['fields'][$k] ?? null;
            if ($field && $field['type'] !== 'image') {
                $config['settings'][$g][$k] = (string) ($pair['value'] ?? '');
            }
        }

        $productIds = collect($cand['product_lines'])->pluck('id')->all();
        $handles = collect($cand['product_lines'])->pluck('handle')->merge(collect($cand['collections'])->pluck('handle'))->all();
        $collectionIds = collect($cand['collections'])->pluck('id')->all();
        $codes = collect($cand['codes'])->pluck('code')->all();
        $existing = collect($list)->keyBy('id');
        $out = [];
        foreach ((array) ($answer['sections'] ?? []) as $raw) {
            $type = (string) ($raw['type'] ?? '');
            $def = $schema['sections'][$type] ?? null;
            if (! $def) {
                continue;
            }
            $old = $existing->get((string) ($raw['id'] ?? ''));
            if ($old && $old['type'] === $type) {
                $block = $old;
            } elseif ($locked) {
                continue;   // markets can't add blocks to a page that follows the main site
            } else {
                $block = $this->themes->newSection($theme, $type);
            }
            $block['enabled'] = (bool) ($raw['enabled'] ?? true);
            foreach ((array) ($raw['settings'] ?? []) as $pair) {
                $key = (string) ($pair['key'] ?? '');
                $value = (string) ($pair['value'] ?? '');
                $f = $def['fields'][$key] ?? null;
                if (! $f || $f['type'] === 'image') {
                    continue;
                }
                $ok = match (true) {
                    $f['type'] === 'product' => $value === '' || in_array((int) $value, $productIds, true),
                    $f['type'] === 'collection' => $value === '' || in_array((int) $value, $collectionIds, true),
                    $f['type'] === 'url' && preg_match('#^/(products|collections)/([^/?\#]+)#', $value, $m) === 1 => $m[2] === 'all' || in_array($m[2], $handles, true),
                    $type === 'coupon_bar' && (bool) preg_match('/^code\d$/', $key) => $value === '' || in_array($value, $codes, true),
                    default => true,
                };
                if ($ok) {
                    $block['settings'][$key] = $value === '' && in_array($f['type'], ['product', 'collection'], true) ? null : $value;
                }
            }
            $out[] = $block;
        }
        if ($locked) {
            // Same blocks, same order: changed values where Claude gave them, everything else untouched
            $changed = collect($out)->keyBy('id');
            $out = array_map(fn ($s) => $changed->get($s['id']) ?? $s, $list);
        }

        return $this->setList($config, $place, $out);
    }

    /** AI background behind the product cards for the first slideshow slide / hero (the image service; GD banner otherwise). */
    private function banner(Store $store, array &$config, string $place, $products, string $brief): void
    {
        $list = $this->listOf($config, $place, '');
        $i = collect($list)->search(fn ($s) => in_array($s['type'], ['slideshow', 'hero'], true));
        if ($i === false) {
            return;
        }
        $images = $this->banners->render($products, (string) ($config['settings']['colors']['primary'] ?? '#333333'), (string) ($config['settings']['colors']['background'] ?? '#FFFFFF'));
        if (! $images) {
            return;
        }
        $desktop = $images['desktop'];
        $scene = SceneImages::for($store);
        $gen = $scene->generator();
        $st = $scene->status();
        if ($gen && $st['used'] < $st['quota']) {
            try {
                $desktop = $gen->edit($images['desktop'], 'Website hero banner. Keep the white product cards and the products on them exactly as they are (same position, shape, colour, text). Replace only the plain background with a tasteful scene that fits this campaign: '
                    .Str::limit($brief, 200).'. Brand colours '.($config['settings']['colors']['primary'] ?? '').'. Soft light, uncluttered, space for a centred headline, no text, no logos, no other products.', '1536x1024');
                $scene->charge();
            } catch (\Throwable $e) {
                report($e);   // the GD banner is still a good banner
            }
        }
        $key = $list[$i]['type'] === 'slideshow' ? ['slide1_image', 'slide1_mobile_image'] : ['image', null];
        $list[$i]['settings'][$key[0]] = $this->banners->store($store, $desktop);
        if ($key[1]) {
            $list[$i]['settings'][$key[1]] = $this->banners->store($store, $images['mobile']);
        }
        $config = $this->setList($config, $place, $list);
    }

    private function structureChanged(array $before, array $after): bool
    {
        return array_map(fn ($s) => [$s['id'], $s['type']], $before) !== array_map(fn ($s) => [$s['id'], $s['type']], $after);
    }

    /** What the proposal changes on this page, for the merchant. @return string[] */
    private function changes(array $schema, array $before, array $after, string $place): array
    {
        $label = fn ($type) => __($schema['sections'][$type]['label'] ?? $type);
        $b = collect($this->listOf($before, $place, ''))->keyBy('id');
        $a = collect($this->listOf($after, $place, ''))->keyBy('id');
        $out = [];
        foreach ($a as $id => $s) {
            if (! $b->has($id)) {
                $out[] = __('新增「:p0」', ['p0' => $label($s['type'])]);
            } elseif ($b[$id]['settings'] != $s['settings'] || $b[$id]['enabled'] !== $s['enabled']) {
                $out[] = $s['enabled'] ? __('修改「:p0」', ['p0' => $label($s['type'])]) : __('隐藏「:p0」', ['p0' => $label($s['type'])]);
            }
        }
        foreach ($b as $id => $s) {
            if (! $a->has($id)) {
                $out[] = __('删除「:p0」', ['p0' => $label($s['type'])]);
            }
        }
        if ($a->keys()->intersect($b->keys())->values()->all() !== $b->keys()->intersect($a->keys())->values()->all()) {
            $out[] = __('调整了模块顺序');
        }
        $settings = 0;
        foreach ($after['settings'] as $g => $fields) {
            foreach ($fields as $k => $v) {
                $settings += (int) (($before['settings'][$g][$k] ?? null) !== $v);
            }
        }
        if ($settings) {
            $out[] = __('修改了 :p0 项主题设置（颜色、字体、版式等）', ['p0' => $settings]);
        }

        return $out;
    }
}
