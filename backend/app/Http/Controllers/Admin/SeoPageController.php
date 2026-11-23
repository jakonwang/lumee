<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\LeadFinder\LeadAi;
use App\Marketing\SeoPages;
use App\Models\Market;
use App\Models\ProductOpportunity;
use App\Models\SeoPage;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Marketing › SEO landing pages: generate from research-radar ideas or a keyword, edit, publish. */
class SeoPageController extends Controller
{
    public function index(CurrentStore $current, SeoPages $pages): JsonResponse
    {
        $store = $current->getOrFail();
        $markets = Market::orderBy('position')->orderBy('id')->get(['id', 'name', 'handle', 'default_locale', 'currency']);
        $list = SeoPage::latest('id')->limit(300)->get();
        $taken = $list->map(fn ($p) => ($p->market_id ?? 0).'|'.$p->keyword)->flip();

        return response()->json([
            'data' => $list->map(fn ($p) => $this->row($p, $pages)),
            // Ideas with local demand that don't have a page yet
            'suggestions' => ProductOpportunity::whereNotNull('score')->where('status', '!=', 'dropped')->orderByDesc('score')->limit(200)->get()
                ->reject(fn ($o) => $taken->has($o->market_id.'|'.$o->keyword))->take(30)
                ->map(fn ($o) => ['id' => $o->id, 'keyword' => $o->keyword, 'market_id' => $o->market_id ?: null, 'score' => $o->score])->values(),
            'markets' => $markets,
            'ai_ready' => LeadAi::ready($store),
            'min_products' => SeoPages::MIN_PRODUCTS,
        ]);
    }

    /** Pages from radar ideas (opportunity_ids) or one keyword for a market. AI: at most 10 at a time. */
    public function generate(Request $request, CurrentStore $current, SeoPages $pages): JsonResponse
    {
        $data = $request->validate([
            'opportunity_ids' => ['array', 'max:50'],
            'opportunity_ids.*' => ['integer'],
            'keyword' => ['nullable', 'string', 'max:120'],
            'market_id' => ['nullable', 'integer'],
            'ai' => ['boolean'],
        ]);
        $store = $current->getOrFail();
        $ai = (bool) ($data['ai'] ?? false);
        $ideas = ProductOpportunity::whereIn('id', $data['opportunity_ids'] ?? [])->get();   // this store's only
        $jobs = $ideas->map(fn ($o) => [$o->market_id ? Market::find($o->market_id) : null, $o->keyword, $o])->all();
        if (filled($data['keyword'] ?? null)) {
            $market = isset($data['market_id']) ? Market::findOrFail($data['market_id']) : null;
            $jobs[] = [$market, $data['keyword'], null];
        }
        if (! $jobs) {
            throw ValidationException::withMessages(['keyword' => __('请填写关键词，或选择选品雷达里的机会')]);
        }
        if ($ai && ! LeadAi::ready($store)) {
            throw ValidationException::withMessages(['ai' => __('还没有配置 Claude API Key（在「获客雷达 › 设置」里填写）')]);
        }
        if ($ai && count($jobs) > 10) {
            throw ValidationException::withMessages(['opportunity_ids' => __('AI 一次最多生成 10 个页面')]);
        }
        $made = [];
        foreach ($jobs as [$market, $keyword, $idea]) {
            $made[] = $this->row($pages->generate($store, $market, $keyword, $ai, $idea), $pages);
        }

        return response()->json(['data' => $made], 201);
    }

    public function show(int $id, SeoPages $pages): JsonResponse
    {
        $page = SeoPage::findOrFail($id);

        return response()->json(['data' => [...$this->row($page, $pages), 'meta_description' => $page->meta_description, 'heading' => $page->heading,
            'intro' => $page->intro, 'faq' => $page->faq ?? [], 'product_ids' => $page->product_ids,
            'products' => $pages->products($page)->map(fn ($p) => ['id' => $p->id, 'title' => $p->title])->values()]]);
    }

    public function update(Request $request, int $id, SeoPages $pages): JsonResponse
    {
        $page = SeoPage::findOrFail($id);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'meta_description' => ['nullable', 'string', 'max:300'],
            'heading' => ['required', 'string', 'max:160'],
            'intro' => ['nullable', 'string', 'max:10000'],
            'faq' => ['array', 'max:8'],
            'faq.*.q' => ['required', 'string', 'max:300'],
            'faq.*.a' => ['required', 'string', 'max:1000'],
            'slug' => ['required', 'string', 'max:140', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('seo_pages', 'slug')->where('store_id', $page->store_id)->ignore($page->id)],
            'product_ids' => ['nullable', 'array', 'max:'.SeoPages::MAX_PRODUCTS],
            'product_ids.*' => ['integer'],
            'status' => ['required', Rule::in(['draft', 'published'])],
        ], ['slug.regex' => __('网址只能用小写字母、数字和连字符')]);
        $page->fill([...$data, 'intro' => SeoPages::clean($data['intro'] ?? null), 'faq' => $data['faq'] ?? [], 'status' => 'draft',
            'product_ids' => $data['product_ids'] ? array_values(array_unique($data['product_ids'])) : null])->save();
        if ($data['status'] === 'published' && ($error = $pages->publish($page))) {
            throw ValidationException::withMessages(['status' => $error]);
        }

        return $this->show($page->id, $pages);
    }

    public function publish(Request $request, SeoPages $pages): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['integer'], 'status' => ['required', Rule::in(['draft', 'published'])]]);
        $done = 0;
        $errors = [];
        foreach (SeoPage::whereIn('id', $data['ids'])->get() as $page) {
            if ($data['status'] === 'draft') {
                $page->forceFill(['status' => 'draft'])->save();
                $done++;
            } elseif ($error = $pages->publish($page)) {
                $errors[] = ['id' => $page->id, 'message' => Str::limit($page->keyword, 40).'：'.$error];
            } else {
                $done++;
            }
        }

        return response()->json(['done' => $done, 'errors' => $errors]);
    }

    public function destroy(int $id): JsonResponse
    {
        SeoPage::findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    private function row(SeoPage $p, SeoPages $pages): array
    {
        $store = app(CurrentStore::class)->getOrFail();

        return ['id' => $p->id, 'market_id' => $p->market_id, 'locale' => $p->locale, 'keyword' => $p->keyword, 'slug' => $p->slug, 'title' => $p->title,
            'status' => $p->status, 'ai' => $p->ai, 'products_count' => $pages->products($p)->count(), 'url' => SeoPages::url($store, $p),
            'updated_at' => $p->updated_at];
    }
}
