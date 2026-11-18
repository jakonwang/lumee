<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StoreTheme;
use App\Tenancy\CurrentStore;
use App\Themes\ThemePackage;
use App\Themes\ThemeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Theme library: the live theme plus saved themes (copies, imports, other templates).
 * Only the live one is shown to shoppers; the others can be edited and previewed safely.
 */
class ThemeLibraryController extends Controller
{
    public const MAX_THEMES = 20;

    public function __construct(private ThemeService $themes) {}

    public function index(CurrentStore $current): JsonResponse
    {
        $store = $current->getOrFail();
        // Older stores may not have a row for their live theme yet
        $live = $this->themes->ensureLive($store);

        $rows = StoreTheme::orderByDesc('updated_at')->get()
            ->sortByDesc(fn ($r) => $r->is($live))->values();

        return response()->json([
            'data' => $rows->map(fn ($r) => $this->present($r, $r->is($live))),
            'templates' => \App\Support\Tr::deep($this->themes->catalog()),
            'max' => self::MAX_THEMES,
        ]);
    }

    /** Add a template to the library, or duplicate a theme. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'theme' => ['required_without:copy_of', 'string', Rule::in(array_keys(config('themes')))],
            'copy_of' => ['nullable', 'integer'],
        ]);
        $this->ensureRoom();

        if (! empty($data['copy_of'])) {
            $src = StoreTheme::findOrFail($data['copy_of']);
            $row = StoreTheme::create([
                'theme' => $src->theme, 'source' => 'copy',
                'name' => Str::limit(__(':p0 的副本', ['p0' => $this->name($src)]), 80, ''),
                'draft' => $src->draft ?? $src->published, 'published' => null,
            ]);
        } else {
            $row = StoreTheme::create(['theme' => $data['theme'], 'source' => 'template', 'draft' => $this->themes->defaults($data['theme'])]);
        }

        return response()->json(['data' => $this->present($row, false)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $row = StoreTheme::findOrFail($id);
        $row->name = trim($data['name']);
        $row->save();

        return response()->json(['data' => $this->present($row, $this->isLive($row))]);
    }

    public function destroy(int $id): JsonResponse
    {
        $row = StoreTheme::findOrFail($id);
        abort_if($this->isLive($row), 422, __('正在使用的主题不能删除，请先发布另一个主题。'));
        $row->delete();

        return response()->json(null, 204);
    }

    public function export(int $id, ThemePackage $package): BinaryFileResponse
    {
        $row = StoreTheme::findOrFail($id);
        $file = Str::slug($this->name($row)) ?: 'theme';

        return response()->download($package->export($row), "{$file}-lumee-theme.zip", ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }

    /** Import a Lumée theme package or a Shopify theme (.zip, or a single settings_data/index .json). */
    public function import(Request $request, ThemePackage $package): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:51200', 'mimes:zip,json,txt'],
            'base' => ['nullable', 'string', Rule::in(array_keys(config('themes')))],
        ], ['file.mimes' => __('请上传 .zip 或 .json 文件'), 'file.max' => __('文件不能超过 50MB')]);
        $this->ensureRoom();

        $result = $package->import($request->file('file'), $request->input('base', 'fresh'));
        $row = StoreTheme::create([
            'theme' => $result['theme'], 'source' => $result['source'], 'name' => $result['name'],
            'draft' => $result['config'], 'published' => null,
        ]);

        return response()->json(['data' => $this->present($row, false), 'report' => $result['report']], 201);
    }

    /** Marketplace: listed themes from developers. */
    public function market(): JsonResponse
    {
        $installed = StoreTheme::whereNotNull('theme_listing_id')->pluck('theme_listing_id')->all();
        $bought = \App\Models\ThemePurchase::where('store_id', app(CurrentStore::class)->id())->pluck('theme_listing_id')->all();

        return response()->json(['data' => \App\Models\ThemeListing::with('developer:id,name')->where('status', 'listed')
            ->orderByDesc('installs')->orderBy('name')->limit(200)->get()
            ->map(fn ($t) => [...\App\Http\Controllers\Admin\PartnerThemeController::present($t),
                'developer' => $t->developer?->name, 'installed' => in_array($t->id, $installed, true), 'purchased' => in_array($t->id, $bought, true)])]);
    }

    /** Add a marketplace theme to this store's library (images are copied into the store). */
    public function installListing(Request $request, int $id, ThemePackage $package): JsonResponse
    {
        $listing = \App\Models\ThemeListing::where('status', 'listed')->findOrFail($id);
        $path = $listing->package_path ? \Illuminate\Support\Facades\Storage::disk('local')->path($listing->package_path) : null;
        abort_unless($path && is_file($path), 404);
        $this->ensureRoom();
        $this->buy($listing, $request);

        $result = $package->import(new \Illuminate\Http\UploadedFile($path, $listing->slug.'.zip', 'application/zip', null, true), 'fresh');
        $row = StoreTheme::create([
            'theme' => $result['theme'], 'source' => 'market', 'name' => \Illuminate\Support\Str::limit($listing->name, 70, ''),
            'draft' => $result['config'], 'published' => null,
        ]);
        $row->forceFill(['theme_listing_id' => $listing->id])->save();
        $listing->increment('installs');

        return response()->json(['data' => $this->present($row, false), 'report' => $result['report']], 201);
    }

    /**
     * Paid theme: one purchase per store (reinstalling is free). The merchant must confirm the price
     * they saw, and the store needs a paid plan so the charge can be collected with it.
     */
    private function buy(\App\Models\ThemeListing $listing, Request $request): void
    {
        $store = app(CurrentStore::class)->getOrFail();
        if ($listing->price <= 0 || \App\Models\ThemePurchase::where('theme_listing_id', $listing->id)->where('store_id', $store->id)->exists()) {
            return;
        }
        if ((int) round(((float) $request->input('confirm_price', -1)) * 100) !== (int) $listing->price) {
            throw \Illuminate\Validation\ValidationException::withMessages(['confirm_price' => __('主题价格已变化，请刷新后重新确认')]);
        }
        if (app(\App\Billing\Billing::class)->state($store) !== 'active') {
            throw \Illuminate\Validation\ValidationException::withMessages(['confirm_price' => __('购买付费主题需要先开通付费套餐')]);
        }
        \Illuminate\Support\Facades\DB::transaction(function () use ($listing, $store, $request) {
            $invoice = app(\App\Billing\MarketplaceInvoices::class)->charge($store, (int) $listing->price, 'themes', __('主题「:p0」', ['p0' => $listing->name]));
            \App\Models\ThemePurchase::create([
                'theme_listing_id' => $listing->id, 'store_id' => $store->id, 'user_id' => $request->user()?->id, 'platform_invoice_id' => $invoice->id,
                'amount' => (int) $listing->price, 'developer_share' => \App\Billing\MarketplaceInvoices::developerShare((int) $listing->price),
            ]);
        });
    }

    private function present(StoreTheme $r, bool $live): array
    {
        $store = app(CurrentStore::class)->getOrFail();
        $config = $r->draft ?? $r->published ?? $this->themes->defaults($r->theme);
        $c = $config['settings']['colors'] ?? [];

        return [
            'id' => $r->id,
            'name' => $this->name($r),
            'theme' => $r->theme,
            'template' => __(config("themes.{$r->theme}.name")),
            'source' => $r->source ?? 'template',
            'live' => $live,
            'swatches' => array_values(array_filter([$c['primary'] ?? null, $c['background'] ?? null, $c['text'] ?? null, $c['accent'] ?? null])),
            'sections' => count($config['sections'] ?? []),
            'has_unpublished_changes' => $live && $r->hasUnpublishedChanges(),
            'updated_at' => $r->updated_at,
            'published_at' => $r->published_at,
            'preview_url' => $store->storefrontUrl().'/?_preview='.$this->themes->previewToken($store, 'r'.$r->id),
        ];
    }

    private function name(StoreTheme $r): string
    {
        return $r->name ?: (string) __(config("themes.{$r->theme}.name"));
    }

    private function isLive(StoreTheme $r): bool
    {
        return $this->themes->liveRow(app(CurrentStore::class)->getOrFail())?->is($r) ?? false;
    }

    private function ensureRoom(): void
    {
        // Pin the live theme first, so a new row of the same template is never mistaken for it
        $this->themes->ensureLive(app(CurrentStore::class)->getOrFail());
        abort_if(StoreTheme::count() >= self::MAX_THEMES, 422, __('主题库最多保存 :p0 个主题，请先删除不用的主题。', ['p0' => self::MAX_THEMES]));
    }
}
