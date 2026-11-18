<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StoreMedia;
use App\Models\StoreTheme;
use App\Tenancy\CurrentStore;
use App\Themes\ThemeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Theme editor API. Every endpoint works on one library theme: ?id= (or "id" in the body), or
 * ?theme= a template key (its live/first copy, created on first use), defaulting to the live theme.
 * Each theme keeps its own draft/published config, so merchants can set up any theme in the editor
 * and only switch to it when they publish.
 */
class ThemeController extends Controller
{
    public function __construct(private ThemeService $themes) {}

    // Resolved per call: Laravel may reuse a controller instance across requests
    private function current(): CurrentStore
    {
        return app(CurrentStore::class);
    }

    public function show(Request $request): JsonResponse
    {
        return $this->respond($this->row($request));
    }

    /** Save the draft (editor autosave). Never affects the live store. */
    public function update(Request $request): JsonResponse
    {
        $row = $this->row($request);
        $request->validate([
            'settings' => ['required', 'array'],
            'sections' => ['present', 'array', 'max:'.$this->themes->schema($row->theme)['max_sections']],
        ]);

        $row->draft = $this->themes->sanitize($row->theme, $request->only('settings', 'sections'));
        $row->save();

        return $this->respond($row);
    }

    /** Publish the draft. Publishing a theme that isn't live also makes it the store's theme. */
    public function publish(Request $request): JsonResponse
    {
        $row = $this->row($request);
        $this->themes->publish($this->current()->getOrFail(), $row);

        return $this->respond($row);
    }

    /** Throw away draft changes and go back to what's published for this theme. */
    public function discard(Request $request): JsonResponse
    {
        $row = $this->row($request);
        $row->draft = $row->published;
        $row->save();

        return $this->respond($row);
    }

    private function respond(StoreTheme $row): JsonResponse
    {
        $store = $this->current()->getOrFail();
        $theme = $row->theme;
        $config = $row->draft ?? $row->published ?? $this->themes->defaults($theme);
        $live = $this->themes->liveRow($store)?->is($row) ?? false;

        $mediaIds = [];
        array_walk_recursive($config, function ($v, $k) use (&$mediaIds) {
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
            'has_unpublished_changes' => ! $live || $row->hasUnpublishedChanges(),
            'published_at' => $row->published_at,
            'preview_url' => $store->storefrontUrl().'/?_preview='.$this->themes->previewToken($store, 'r'.$row->id),
            'storefront_url' => $store->storefrontUrl(),
        ]);
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
