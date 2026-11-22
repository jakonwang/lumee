<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Market;
use App\Models\StoreMedia;
use App\Models\StoreTheme;
use App\Models\ThemeVersion;
use App\Tenancy\CurrentStore;
use App\Themes\MarketThemes;
use App\Themes\ThemeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Theme editor API. Every endpoint works on one library theme: ?id= (or "id" in the body), or
 * ?theme= a template key (its live/first copy, created on first use), defaulting to the live theme.
 * Each theme keeps its own draft/published config, so merchants can set up any theme in the editor
 * and only switch to it when they publish.
 *
 * With ?market= (or "market" in the body) the editor works on that market's design on top of the
 * theme: it still sends and receives a complete config; the server keeps only what differs from the
 * main site (MarketThemes). "own_sections" gives the market a home page of its own.
 */
class ThemeController extends Controller
{
    public function __construct(private ThemeService $themes, private MarketThemes $markets) {}

    // Resolved per call: Laravel may reuse a controller instance across requests
    private function current(): CurrentStore
    {
        return app(CurrentStore::class);
    }

    public function show(Request $request): JsonResponse
    {
        return $this->respond($this->row($request), $this->market($request));
    }

    /** Save the draft (editor autosave). Never affects the live store. */
    public function update(Request $request): JsonResponse
    {
        $row = $this->row($request);
        $market = $this->market($request);
        $request->validate([
            'settings' => ['required', 'array'],
            'sections' => ['present', 'array', 'max:'.$this->themes->schema($row->theme)['max_sections']],
            'own_sections' => ['sometimes', 'boolean'],
            'templates' => ['sometimes', 'array'],
            'pages' => ['sometimes', 'array'],
        ]);
        $config = $this->themes->sanitize($row->theme, $request->only('settings', 'sections', 'templates', 'pages'));

        if ($market) {
            $this->markets->saveDraft($row, $market, $config, $request->boolean('own_sections'));
        } else {
            $row->draft = $config;
            $row->save();
        }

        return $this->respond($row, $market);
    }

    /** Publish the draft. Publishing a theme that isn't live also makes it the store's theme; a market's design is published on its own. */
    public function publish(Request $request): JsonResponse
    {
        $row = $this->row($request);
        $market = $this->market($request);
        if ($market) {
            $this->markets->publish($row, $market, $request->user()?->id);
        } else {
            $this->themes->publish($this->current()->getOrFail(), $row, $request->user()?->id);
        }

        return $this->respond($row, $market);
    }

    /** Throw away draft changes and go back to what's published for this theme (or market). */
    public function discard(Request $request): JsonResponse
    {
        $row = $this->row($request);
        $market = $this->market($request);
        if ($market) {
            $this->markets->discard($row, $market);
        } else {
            $row->draft = $row->published;
            $row->save();
        }

        return $this->respond($row, $market);
    }

    /**
     * Automatic design: build the home page and look for the main site or a market (draft only),
     * optionally as a campaign version for a calendar event. Returns the editor payload, what was done
     * and the design check of the result.
     */
    public function generate(Request $request, \App\Themes\AutoDesigner $designer, \App\Themes\DesignCheck $check): JsonResponse
    {
        $row = $this->row($request);
        $market = $this->market($request);
        $request->validate(['event_key' => ['nullable', 'string', 'max:40']]);
        $result = $designer->generate($this->current()->getOrFail(), $row, $market, $request->input('event_key'));
        $data = $this->respond($row, $market)->getData(true);

        return response()->json([...$data, 'notes' => $result['notes'], 'region' => $result['region'],
            'issues' => $check->run($this->current()->getOrFail(), $row, $market)]);
    }

    // ── AI design (App\Themes\AiDesigner): proposals are previewed, then applied to the draft ──

    /** A brief ("build") or an assistant request ("edit") for one page → a proposal with a preview link. */
    public function aiPropose(Request $request, \App\Themes\AiDesigner $ai): JsonResponse
    {
        $row = $this->row($request);
        $market = $this->market($request);
        $data = $request->validate([
            'place' => ['required', 'string', 'regex:/^(home|product|collection|cart|page:\d+)$/'],
            'request' => ['required', 'string', 'max:1000'],
            'mode' => ['required', \Illuminate\Validation\Rule::in(['build', 'edit'])],
            'ai_banner' => ['sometimes', 'boolean'],
        ]);
        $store = $this->current()->getOrFail();
        $p = $this->ai(fn () => $ai->propose($store, $row, $market, $data['place'], $data['request'], $data['mode'], $request->boolean('ai_banner')));
        $place = collect($this->places($store, $row, $market))->firstWhere('key', $data['place']);

        return response()->json([...$p, 'preview_url' => $place ? $place['preview_url'].'&_proposal='.$p['token'] : null, 'ai' => \App\Themes\AiDesigner::status($store->refresh())]);
    }

    /** Put a proposal into the draft (the draft before is kept as a version). */
    public function aiApply(Request $request, \App\Themes\AiDesigner $ai): JsonResponse
    {
        $token = (string) $request->validate(['token' => ['required', 'string']])['token'];
        [$row, $market] = $this->ai(fn () => $ai->apply($this->current()->getOrFail(), $token, $request->user()?->id));

        return $this->respond($row, $market);
    }

    /** The design check plus Claude's advice for one page. */
    public function aiReview(Request $request, \App\Themes\AiDesigner $ai): JsonResponse
    {
        $place = (string) $request->validate(['place' => ['required', 'string', 'regex:/^(home|product|collection|cart|page:\d+)$/']])['place'];
        $store = $this->current()->getOrFail();

        return response()->json([...$this->ai(fn () => $ai->review($store, $this->row($request), $this->market($request), $place)), 'ai' => \App\Themes\AiDesigner::status($store->refresh())]);
    }

    /** Another market's home page rewritten for this market (saved as this market's draft). */
    public function aiLocalize(Request $request, \App\Themes\AiDesigner $ai): JsonResponse
    {
        $row = $this->row($request);
        $data = $request->validate(['from' => ['nullable', 'integer'], 'to' => ['required', 'integer']]);
        $from = ! empty($data['from']) ? Market::findOrFail($data['from']) : null;
        $to = Market::findOrFail($data['to']);
        abort_if($from?->id === $to->id, 422);
        $notes = $this->ai(fn () => $ai->localize($this->current()->getOrFail(), $row, $from, $to, $request->user()?->id));

        return response()->json([...$this->respond($row, $to)->getData(true), 'notes' => $notes]);
    }

    /** AI errors (no key, allowance used up, service down…) as a message for the editor. */
    private function ai(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\RuntimeException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages(['ai' => $e->getMessage()]);
        }
    }

    /** A/B test the current draft (B) against the live design (A) on this site. */
    public function startExperiment(Request $request, \App\Themes\DesignExperiments $tests): JsonResponse
    {
        $row = $this->row($request);
        $market = $this->market($request);
        $name = (string) $request->validate(['name' => ['required', 'string', 'max:120']])['name'];
        abort_unless($this->themes->liveRow($this->current()->getOrFail())?->is($row), 422, __('只能测试正在使用的主题'));
        $e = $tests->start($row, $market, $name, $request->user()?->id);

        return response()->json(['data' => $e->only('id', 'name', 'status', 'started_at')], 201);
    }

    /** Design check of the draft (also run before publishing). */
    public function check(Request $request, \App\Themes\DesignCheck $check): JsonResponse
    {
        return response()->json(['issues' => $check->run($this->current()->getOrFail(), $this->row($request), $this->market($request))]);
    }

    /** Campaign versions of this theme for the main site / a market, and the upcoming shopping events. */
    public function schedules(Request $request): JsonResponse
    {
        $row = $this->row($request);
        $market = $this->market($request);
        $store = $this->current()->getOrFail();
        $rows = \App\Models\ThemeSchedule::where('store_theme_id', $row->id)->where('market_id', $market?->id)
            ->whereIn('status', ['review', 'scheduled', 'active'])->orWhere(fn ($q) => $q->where('store_theme_id', $row->id)->where('market_id', $market?->id)->where('ends_at', '>', now()->subDays(30)))
            ->orderBy('starts_at')->get();
        $byEvent = $rows->whereNotIn('status', ['cancelled'])->keyBy('event_key');

        return response()->json([
            'data' => $rows->map(fn ($s) => $s->only('id', 'name', 'event_key', 'starts_at', 'ends_at', 'status', 'source'))->values(),
            'events' => collect(\App\Markets\ShoppingCalendar::upcoming($market ? (array) $market->countries : []))->take(8)->map(fn ($e) => [
                ...\Illuminate\Support\Arr::only($e, ['key', 'start', 'end', 'prep_from', 'days_left', 'preparing']), 'name' => __($e['name']),
                'schedule_id' => $byEvent->get($e['key'])?->id,
            ])->values(),
            'auto' => \App\Themes\ThemeSchedules::autoEnabled($store),
            'timezone' => $store->timezone ?: 'UTC',
        ]);
    }

    /** Schedule a campaign version: from a calendar event (built automatically) or from the current draft. */
    public function schedule(Request $request, \App\Themes\ThemeSchedules $schedules): JsonResponse
    {
        $row = $this->row($request);
        $market = $this->market($request);
        $data = $request->validate([
            'event_key' => ['nullable', 'string', 'max:40'],
            'name' => ['required_without:event_key', 'nullable', 'string', 'max:120'],
            'starts_at' => ['required_without:event_key', 'nullable', 'date'],
            'ends_at' => ['required_without:event_key', 'nullable', 'date'],
        ]);
        $store = $this->current()->getOrFail();
        $tz = $store->timezone ?: 'UTC';
        $s = ! empty($data['event_key'])
            ? $schedules->fromEvent($store, $row, $market, $data['event_key'], 'scheduled', $request->user()?->id)
            : $schedules->fromDraft($row, $market, $data['name'], \Carbon\CarbonImmutable::parse($data['starts_at'], $tz), \Carbon\CarbonImmutable::parse($data['ends_at'], $tz), $request->user()?->id);

        return response()->json(['data' => $s->only('id', 'name', 'event_key', 'starts_at', 'ends_at', 'status', 'source')], 201);
    }

    public function approveSchedule(\App\Models\ThemeSchedule $schedule, \App\Themes\ThemeSchedules $schedules): JsonResponse
    {
        return response()->json(['data' => $schedules->approve($schedule)->only('id', 'status')]);
    }

    public function cancelSchedule(\App\Models\ThemeSchedule $schedule, \App\Themes\ThemeSchedules $schedules): JsonResponse
    {
        return response()->json(['data' => $schedules->cancel($schedule)->only('id', 'status')]);
    }

    /** Prepare campaign versions for upcoming shopping events by themselves (they wait for review). */
    public function autoCampaigns(Request $request): JsonResponse
    {
        $on = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        $store = $this->current()->getOrFail();
        $settings = $store->settings ?? [];
        $settings['design']['auto_campaigns'] = $on;
        $store->forceFill(['settings' => $settings])->save();
        $made = $on ? app(\App\Themes\ThemeSchedules::class)->prepare($store) : 0;

        return response()->json(['auto' => $on, 'prepared' => $made]);
    }

    /** Published versions of the theme (or of one market's design), newest first. */
    public function versions(Request $request): JsonResponse
    {
        $row = $this->row($request);
        $market = $this->market($request);
        $rows = ThemeVersion::where('store_theme_id', $row->id)->where('market_id', $market?->id)->orderByDesc('id')->limit(30)->get();
        $names = \App\Models\User::whereIn('id', $rows->pluck('created_by')->filter())->pluck('name', 'id');

        return response()->json(['data' => $rows->map(fn (ThemeVersion $v) => [
            'id' => $v->id, 'source' => $v->source, 'note' => $v->note, 'by' => $names[$v->created_by] ?? null, 'created_at' => $v->created_at,
        ])]);
    }

    /** Put an earlier version back into the draft (publish to make it live). */
    public function restoreVersion(Request $request, ThemeVersion $version): JsonResponse
    {
        $row = StoreTheme::findOrFail($version->store_theme_id);
        $market = $version->market_id ? Market::findOrFail($version->market_id) : null;
        if ($market) {
            $m = $this->markets->rowOrNew($row, $market);
            $m->draft = $version->config;
            $m->save();
        } else {
            $row->draft = $this->themes->sanitize($row->theme, $version->config, checkMedia: false);
            $row->save();
        }

        return $this->respond($row, $market);
    }

    private function respond(StoreTheme $row, ?Market $market = null): JsonResponse
    {
        $store = $this->current()->getOrFail();
        $theme = $row->theme;
        $base = $this->withLandingPages($row->theme, $this->markets->base($row, true));
        $config = $market ? $this->withLandingPages($row->theme, $this->markets->effective($row, $market)) : $base;
        $live = $this->themes->liveRow($store)?->is($row) ?? false;
        $layers = $this->markets->layers($row);
        $layer = $market ? ($layers[$market->id] ?? null) : null;

        $mediaIds = [];
        $walk = [$config, $market ? $base : []];   // main-site images too, for "restore" in a market
        array_walk_recursive($walk, function ($v, $k) use (&$mediaIds) {
            if (in_array($k, ['image', 'logo'], true) && is_int($v)) {
                $mediaIds[] = $v;
            }
        });

        return response()->json([
            'id' => $row->id,
            'name' => $row->name ?: __(config("themes.$theme.name")),
            'theme' => $theme,
            'active_theme' => $this->themes->activeTheme($store),
            'live' => $live,
            'themes' => \App\Support\Tr::deep($this->themes->catalog()),
            'schema' => \App\Support\Tr::deep($this->themes->schema($theme)),
            'config' => $config,
            'media' => StoreMedia::whereIn('id', $mediaIds)->get()->map->toApi()->values(),
            // A theme that isn't live always has something to publish (publishing switches to it)
            'has_unpublished_changes' => $market ? (bool) $layer?->hasUnpublishedChanges() : (! $live || $row->hasUnpublishedChanges()),
            'published_at' => $market ? $layer?->published_at : $row->published_at,
            'preview_url' => $store->storefrontUrl().($market ? $market->prefix() : '').'/?_preview='.$this->themes->previewToken($store, 'r'.$row->id),
            // What can be designed: home, product / collection / cart templates, campaign landing pages; each with its preview
            'places' => $this->places($store, $row, $market),
            'storefront_url' => $store->storefrontUrl().($market ? $market->prefix().'/' : ''),
            // Markets: which have their own design on this theme; the one being edited and the main site's config to compare with
            'markets' => Market::orderBy('position')->orderBy('id')->get()->map(fn (Market $m) => [
                'id' => $m->id, 'name' => $m->name, 'currency' => $m->currency, 'locale' => $m->default_locale, 'enabled' => (bool) $m->enabled,
                'customized' => MarketThemes::isCustomized($layers[$m->id] ?? null),
                'has_unpublished_changes' => (bool) ($layers[$m->id] ?? null)?->hasUnpublishedChanges(),
            ])->values(),
            'market' => $market ? [
                'id' => $market->id, 'name' => $market->name, 'locale' => $market->default_locale,
                'own_sections' => is_array(($layer?->draft ?? $layer?->published)['sections'] ?? null),
            ] : null,
            'base' => $market ? $this->markets->base($row, true) : null,
            'ai' => \App\Themes\AiDesigner::status($store),
            // A design A/B test running on this site (its B is a frozen copy of the draft at the start)
            'experiment' => ($e = app(\App\Themes\DesignExperiments::class)->running($row->id, $market?->id)) ? $e->only('id', 'name', 'started_at') : null,
        ]);
    }

    /** Campaign landing pages (layout "blocks") that aren't designed yet start from the default blocks. */
    private function withLandingPages(string $theme, array $config): array
    {
        $pages = (array) ($config['pages'] ?? []);
        foreach (\App\Models\Page::where('layout', 'blocks')->orderBy('id')->pluck('id') as $id) {
            $pages[(string) $id] ??= $this->themes->pageDefaults($theme, $id);
        }
        $config['pages'] = (object) $pages;

        return $config;
    }

    /** @return array<int, array{key: string, place: string, label: string, preview_url: string}> */
    private function places(\App\Models\Store $store, StoreTheme $row, ?Market $market): array
    {
        $base = $store->storefrontUrl().($market ? $market->prefix() : '');
        $q = '?_preview='.$this->themes->previewToken($store, 'r'.$row->id);
        $product = \App\Models\Product::where('status', 'active')->latest('id')->value('handle');
        $schema = $this->themes->schema($row->theme);
        $out = [['key' => 'home', 'place' => 'home', 'label' => __('首页'), 'preview_url' => $base.'/'.$q]];
        foreach (['product' => $product ? '/products/'.$product : null, 'collection' => '/collections/all', 'cart' => '/cart'] as $key => $path) {
            if (isset($schema['templates'][$key]) && $path) {
                $out[] = ['key' => $key, 'place' => $key, 'label' => __($schema['templates'][$key]['label']), 'preview_url' => $base.$path.$q];
            }
        }
        foreach (\App\Models\Page::where('layout', 'blocks')->orderBy('id')->get(['id', 'title', 'handle']) as $p) {
            $out[] = ['key' => 'page:'.$p->id, 'place' => 'page', 'label' => __('活动页 · :title', ['title' => $p->title]), 'preview_url' => $base.'/pages/'.$p->handle.$q];
        }

        return $out;
    }

    private function market(Request $request): ?Market
    {
        $id = $request->input('market', $request->query('market'));

        return $id ? Market::findOrFail((int) $id) : null;   // store-scoped
    }

    private function row(Request $request): StoreTheme
    {
        $id = $request->input('id', $request->query('id'));
        if ($id !== null) {
            return StoreTheme::findOrFail((int) $id);   // store-scoped
        }

        $store = $this->current()->getOrFail();
        $key = $request->input('theme', $request->query('theme'));
        if ($key !== null) {
            abort_unless(is_string($key) && config("themes.$key") !== null, 404, __('主题不存在'));
        }
        $live = $this->themes->ensureLive($store);
        if ($key === null || $key === $live->theme) {
            return $live;
        }

        return $this->themes->rowForRef($store, $key) ?? StoreTheme::create(['theme' => $key, 'source' => 'template']);
    }
}
