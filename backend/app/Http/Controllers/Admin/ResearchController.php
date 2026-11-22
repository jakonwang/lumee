<?php

namespace App\Http\Controllers\Admin;

use App\Collect\CollectAi;
use App\Http\Controllers\Controller;
use App\Models\Market;
use App\Models\ProductOpportunity;
use App\Research\Radar;
use App\Research\ResearchAi;
use App\Research\SerpApi;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Products › Research radar (选品雷达). */
class ResearchController extends Controller
{
    public function index(Request $request, CurrentStore $current): JsonResponse
    {
        $store = $current->getOrFail();
        $market = $request->query('market', 'all');
        $status = $request->query('status', 'open');
        $q = trim((string) $request->query('q'));
        $page = ProductOpportunity::query()
            ->when($market !== 'all', fn ($w) => $w->where('market_id', (int) $market))
            ->when($status === 'open', fn ($w) => $w->whereNotIn('status', ['won', 'dropped']))
            ->when(in_array($status, ProductOpportunity::STATUSES, true), fn ($w) => $w->where('status', $status))
            ->when($q !== '', fn ($w) => $w->where('keyword', 'like', '%'.addcslashes(mb_strtolower($q), '%_\\').'%'))
            ->orderByRaw('score IS NULL')->orderByDesc('score')->orderByDesc('id')->paginate(30);
        $markets = Market::pluck('name', 'id');

        return response()->json([
            'data' => collect($page->items())->map(fn ($o) => $this->row($store, $o, $markets)),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
            'counts' => ProductOpportunity::selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status'),
            'markets' => Market::orderBy('position')->get(['id', 'name', 'currency']),
            'serpapi_connected' => Radar::serpApiKey($store) !== null,
            'ai_ready' => CollectAi::ready($store),
            'weights' => Radar::WEIGHTS,
        ]);
    }

    public function saveSettings(Request $request, CurrentStore $current): JsonResponse
    {
        $data = $request->validate(['serpapi_key' => ['nullable', 'string', 'max:200']]);
        Radar::saveKey($current->getOrFail(), $data['serpapi_key'] ? trim($data['serpapi_key']) : null);

        return response()->json(['serpapi_connected' => Radar::serpApiKey($current->getOrFail()) !== null]);
    }

    /** Ideas from the store's own searches and best-testing product types. */
    public function discover(CurrentStore $current, Radar $radar): JsonResponse
    {
        return response()->json(['created' => $radar->discover($current->getOrFail())]);
    }

    /** Add keywords by hand (one per line) for a market. */
    public function store(Request $request, Radar $radar): JsonResponse
    {
        $data = $request->validate([
            'keywords' => ['required', 'string', 'max:5000'],
            'market_id' => ['required', 'integer', 'min:0'],
        ]);
        if ($data['market_id'] && ! Market::whereKey($data['market_id'])->exists()) {
            throw ValidationException::withMessages(['market_id' => __('市场不存在')]);
        }
        $words = collect(preg_split('/[\r\n,，;；]+/u', $data['keywords']))->map(fn ($w) => trim($w))->filter()->unique()->take(50);
        $created = $words->sum(fn ($w) => $radar->upsert((int) $data['market_id'], $w, 'manual'));

        return response()->json(['created' => $created, 'total' => $words->count()], 201);
    }

    /**
     * Import keyword data exported from another tool (TikTok Creative Center, Keepa, Helium 10…) as CSV.
     * Volume, growth, price and seller counts become evidence in the score.
     */
    public function import(Request $request, Radar $radar): JsonResponse
    {
        $data = $request->validate([
            'csv' => ['required', 'string', 'max:500000'],
            'market_id' => ['required', 'integer', 'min:0'],
            'source' => ['required', 'string', 'max:40'],
        ]);
        if ($data['market_id'] && ! Market::whereKey($data['market_id'])->exists()) {
            throw ValidationException::withMessages(['market_id' => __('市场不存在')]);
        }
        $rows = \App\Research\ExternalImport::parse($data['csv']);
        if (! $rows) {
            throw ValidationException::withMessages(['csv' => __('没有读到关键词：第一行需要是表头（例如 keyword, volume, growth），或者每行一个关键词')]);
        }
        $source = trim($data['source']);
        $created = 0;
        foreach ($rows as $r) {
            $created += $radar->upsert((int) $data['market_id'], $r['keyword'], 'import', ['external' => [
                'source' => $source, 'volume' => $r['volume'], 'growth' => $r['growth'], 'price' => $r['price'], 'sellers' => $r['sellers'],
                'imported_at' => now()->toDateString(),
            ]]);
        }

        return response()->json(['created' => $created, 'total' => count($rows)], 201);
    }

    public function update(Request $request, ProductOpportunity $opportunity): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(ProductOpportunity::STATUSES)],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        $opportunity->update($data);

        return response()->json(['ok' => true]);
    }

    public function destroy(ProductOpportunity $opportunity): JsonResponse
    {
        $opportunity->delete();

        return response()->json(['ok' => true]);
    }

    /** Fetch trend + shopping evidence (SerpApi, the merchant's key; 2 searches per idea). */
    public function enrich(Request $request, CurrentStore $current, Radar $radar): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:20'], 'ids.*' => ['integer']]);
        $store = $current->getOrFail();
        $api = SerpApi::for($store) ?? throw ValidationException::withMessages(['ids' => __('还没有填写 SerpApi Key（选品雷达 › 设置）')]);

        return response()->json($this->each($data['ids'], fn ($o) => $radar->enrich($store, $o, $api)));
    }

    /** Claude's market analysis (≤ 5 at a time). */
    public function analyze(Request $request, CurrentStore $current, Radar $radar, ResearchAi $ai): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:5'], 'ids.*' => ['integer']]);
        $store = $current->getOrFail();
        if (! CollectAi::ready($store)) {
            throw ValidationException::withMessages(['ids' => __('还没有配置 Claude API Key（在「获客雷达 › 设置」里填写）')]);
        }

        return response()->json($this->each($data['ids'], function ($o) use ($store, $radar, $ai) {
            $o->forceFill(['ai' => $ai->analyze($store, $o), 'analyzed_at' => now()])->save();
            $radar->rescore($o);
        }));
    }

    private function each(array $ids, callable $fn): array
    {
        $done = 0;
        $errors = [];
        foreach (ProductOpportunity::whereIn('id', $ids)->get() as $o) {   // this store's only
            try {
                $fn($o);
                $done++;
            } catch (Throwable $e) {
                $errors[] = ['id' => $o->id, 'keyword' => $o->keyword, 'message' => Str::limit($e->getMessage(), 200)];
                if (str_contains($e->getMessage(), 'Key 无效') || str_contains($e->getMessage(), '额度')) {
                    break;   // every next one would fail the same way
                }
            }
        }

        return ['done' => $done, 'errors' => $errors];
    }

    private function row($store, ProductOpportunity $o, $markets): array
    {
        $m = Radar::market($store, $o->market_id);

        return [
            ...$o->only('id', 'market_id', 'keyword', 'source', 'status', 'signals', 'scores', 'score', 'ai', 'note', 'enriched_at', 'analyzed_at'),
            'market' => $o->market_id ? ($markets[$o->market_id] ?? '—') : __('默认站点'),
            'links' => Radar::sourcingLinks($o, $m),
        ];
    }
}
