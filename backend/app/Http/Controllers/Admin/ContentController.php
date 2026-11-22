<?php

namespace App\Http\Controllers\Admin;

use App\Collect\CollectAi;
use App\Content\BrandKit;
use App\Content\ContentAi;
use App\Content\ContentScorer;
use App\Content\ContentVersions;
use App\Content\ImageStudio;
use App\Http\Controllers\Controller;
use App\I18n\Localization;
use App\Models\Product;
use App\Models\ProductContentVersion;
use App\Models\ProductImage;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Products › Content workshop (内容工坊): brand kit, quality scores, AI rewrites with version
 * history, and image standardizing.
 */
class ContentController extends Controller
{
    public function brandKit(CurrentStore $current): JsonResponse
    {
        $store = $current->getOrFail();

        return response()->json(['data' => BrandKit::for($store)->all(), 'sections' => BrandKit::SECTIONS, 'ratios' => BrandKit::RATIOS,
            'image_ai' => \App\Content\SceneImages::for($store)->status(), 'scenes' => array_keys(\App\Content\SceneImages::SCENES)]);
    }

    public function saveBrandKit(Request $request, CurrentStore $current): JsonResponse
    {
        $data = $request->validate([
            'positioning' => ['nullable', 'string', 'max:300'],
            'audience' => ['nullable', 'string', 'max:300'],
            'voice' => ['nullable', 'string', 'max:300'],
            'banned_words' => ['array', 'max:100'],
            'banned_words.*' => ['string', 'max:60'],
            'title_pattern' => ['nullable', 'string', 'max:200'],
            'title_min' => ['required', 'integer', 'min:5', 'max:200'],
            'title_max' => ['required', 'integer', 'gte:title_min', 'max:255'],
            'ratio' => ['required', Rule::in(BrandKit::RATIOS)],
            'background' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'padding' => ['required', 'integer', 'min:0', 'max:30'],
            'min_images' => ['required', 'integer', 'min:1', 'max:12'],
            'sections' => ['array'],
            'sections.*' => [Rule::in(BrandKit::SECTIONS)],
            'min_score' => ['required', 'integer', 'min:0', 'max:100'],
            'removebg_key' => ['nullable', 'string', 'max:200'],
            'image_ai_provider' => ['nullable', Rule::in(array_keys(\App\Content\SceneImages::PROVIDERS))],
            'image_ai_key' => ['nullable', 'string', 'max:300'],
            'image_ai_quota' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);
        foreach (['positioning', 'audience', 'voice', 'title_pattern'] as $f) {
            $data[$f] = (string) ($data[$f] ?? '');
        }
        $kit = BrandKit::for($current->getOrFail());
        $kit->save($data);
        \App\Content\SceneImages::for($current->getOrFail()->refresh())->save($request->only('image_ai_provider', 'image_ai_key', 'image_ai_quota'));

        return $this->brandKit($current);
    }

    /** Products by content score (lowest first), with the main problems. Scores of this page are refreshed. */
    public function products(Request $request, CurrentStore $current, ContentScorer $scorer): JsonResponse
    {
        $store = $current->getOrFail();
        $max = $request->integer('max', 100);
        $q = trim((string) $request->query('q'));
        $page = Product::query()
            ->when($q !== '', fn ($w) => $w->where('title', 'like', '%'.addcslashes($q, '%_\\').'%'))
            ->when($max < 100, fn ($w) => $w->where(fn ($x) => $x->whereNull('content_score')->orWhere('content_score', '<=', $max)))
            ->orderByRaw('content_score IS NOT NULL')->orderBy('content_score')->orderByDesc('id')
            ->with('images', 'variants')->paginate(25);

        $rows = collect($page->items())->map(function (Product $p) use ($store, $scorer) {
            $r = $scorer->score($store, $p);
            if ($p->content_score !== $r['score']) {
                Product::whereKey($p->id)->update(['content_score' => $r['score'], 'content_scored_at' => now()]);
            }

            return [
                'id' => $p->id, 'title' => $p->getRawOriginal('title'), 'status' => $p->status, 'image' => $p->images->first()?->url(),
                'score' => $r['score'], 'issues' => collect($r['checks'])->whereNotNull('message')->sortByDesc(fn ($c) => $c['max'] - $c['points'])->pluck('message')->take(3)->values(),
            ];
        });
        $scored = Product::whereNotNull('content_score');

        return response()->json([
            'data' => $rows,
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
            'summary' => [
                'average' => (int) round((float) (clone $scored)->avg('content_score')),
                'low' => (clone $scored)->where('content_score', '<', 60)->count(),
                'unscored' => Product::whereNull('content_score')->count(),
                'total' => Product::count(),
            ],
            'ai_ready' => CollectAi::ready($store),
            'min_score' => BrandKit::for($store)->all()['min_score'],
        ]);
    }

    /** Re-score every product (after changing the brand kit). */
    public function rescore(CurrentStore $current, ContentScorer $scorer): JsonResponse
    {
        $store = $current->getOrFail();
        $n = 0;
        Product::with('images', 'variants')->chunkById(200, function ($products) use ($store, $scorer, &$n) {
            foreach ($products as $p) {
                $scorer->rescore($store, $p);
                $n++;
            }
        });

        return response()->json(['scored' => $n]);
    }

    /** One product: score with every check, languages, version history. */
    public function product(Product $product, CurrentStore $current, ContentScorer $scorer): JsonResponse
    {
        $store = $current->getOrFail();
        $r = $scorer->score($store, $product);
        Product::whereKey($product->id)->update(['content_score' => $r['score'], 'content_scored_at' => now()]);
        $default = Localization::storeDefault($store);

        return response()->json(['data' => [
            ...$r,
            'languages' => collect([$default, ...$scorer->languages($store)])->map(fn ($c) => ['code' => $c, 'name' => Localization::languages()[$c] ?? $c, 'default' => $c === $default])->values(),
            'versions' => ProductContentVersion::where('product_id', $product->id)->latest('id')->limit(30)
                ->get(['id', 'locale', 'title', 'reason', 'created_at']),
            'ai_ready' => CollectAi::ready($store),
            'images' => $product->images->map->toApi(),
            'image_ai' => \App\Content\SceneImages::for($store)->status(),
        ]]);
    }

    /** AI suggestion for one language (nothing is saved). */
    public function suggest(Request $request, Product $product, CurrentStore $current): JsonResponse
    {
        $store = $current->getOrFail();
        $locale = $this->locale($request, $store);
        if (! CollectAi::ready($store)) {
            throw ValidationException::withMessages(['locale' => __('还没有配置 Claude API Key（在「获客雷达 › 设置」里填写）')]);
        }
        try {
            $s = app(ContentAi::class)->suggest($store, $product, $locale);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['locale' => Str::limit($e->getMessage(), 300)]);
        }
        $cur = ContentVersions::current($product, $locale === Localization::storeDefault($store) ? null : $locale);

        return response()->json(['data' => ['suggestion' => $s, 'current' => [
            'title' => $cur['title'], 'description' => $cur['description'],
            'seo_title' => $cur['seo']['title'] ?? null, 'seo_description' => $cur['seo']['description'] ?? null,
        ]]]);
    }

    /** Save accepted content for a language (the previous text goes to the history). */
    public function apply(Request $request, Product $product, CurrentStore $current, ContentVersions $versions, ContentScorer $scorer): JsonResponse
    {
        $store = $current->getOrFail();
        $locale = $this->locale($request, $store);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:60000'],
            'seo_title' => ['nullable', 'string', 'max:70'],
            'seo_description' => ['nullable', 'string', 'max:320'],
        ]);
        $data['description'] = ContentAi::safeHtml((string) ($data['description'] ?? ''));
        $versions->apply($product, $locale === Localization::storeDefault($store) ? null : $locale, $data, 'before_ai');

        return response()->json(['score' => $scorer->rescore($store, $product->fresh())]);
    }

    public function restore(Product $product, ProductContentVersion $version, CurrentStore $current, ContentVersions $versions, ContentScorer $scorer): JsonResponse
    {
        abort_unless($version->product_id === $product->id, 404);
        $versions->restore($product, $version);

        return response()->json(['score' => $scorer->rescore($current->getOrFail(), $product->fresh())]);
    }

    /** Standardize images of up to 50 products: the main image, or every image. */
    public function standardize(Request $request, CurrentStore $current, ImageStudio $studio, ContentScorer $scorer): JsonResponse
    {
        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1', 'max:50'],
            'product_ids.*' => ['integer'],
            'scope' => ['required', Rule::in(['main', 'all'])],
            'remove_background' => ['boolean'],
        ]);
        $store = $current->getOrFail();
        $done = 0;
        $errors = [];
        foreach (Product::whereIn('id', $data['product_ids'])->with('images')->get() as $p) {
            $images = $data['scope'] === 'main' ? $p->images->take(1) : $p->images;
            foreach ($images as $img) {
                try {
                    $studio->standardize($store, $img, (bool) ($data['remove_background'] ?? false));
                    $done++;
                } catch (Throwable $e) {
                    $errors[] = ['product_id' => $p->id, 'message' => Str::limit($e->getMessage(), 200)];
                    break;
                }
            }
            $scorer->rescore($store, $p->fresh(['images', 'variants']));
        }

        return response()->json(['done' => $done, 'errors' => $errors]);
    }

    /** AI scene / model picture from one of the product's photos (added as a new image). */
    public function sceneImage(Request $request, Product $product, ProductImage $image, CurrentStore $current, ContentScorer $scorer): JsonResponse
    {
        abort_unless($image->product_id === $product->id, 404);
        $data = $request->validate([
            'scene' => ['required', Rule::in(array_keys(\App\Content\SceneImages::SCENES))],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        $store = $current->getOrFail();
        try {
            $img = \App\Content\SceneImages::for($store)->generate($product, $image, $data['scene'], $data['note'] ?? null);
        } catch (\RuntimeException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages(['scene' => $e->getMessage()]);
        }
        $scorer->rescore($store, $product->fresh(['images', 'variants']));

        return response()->json(['data' => $img->toApi(), 'image_ai' => \App\Content\SceneImages::for($store->refresh())->status()], 201);
    }

    public function revertImage(Product $product, ProductImage $image, ImageStudio $studio): JsonResponse
    {
        abort_unless($image->product_id === $product->id, 404);

        return response()->json(['data' => $studio->revert($image)->toApi()]);
    }

    private function locale(Request $request, $store): string
    {
        $locale = (string) $request->input('locale', Localization::storeDefault($store));
        $allowed = [Localization::storeDefault($store), ...app(ContentScorer::class)->languages($store)];
        if (! in_array($locale, $allowed, true)) {
            throw ValidationException::withMessages(['locale' => __('这个语言没有在店铺或任何市场中使用')]);
        }

        return $locale;
    }
}
