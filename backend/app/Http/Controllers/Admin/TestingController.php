<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\ContentExperiment;
use App\Models\Market;
use App\Models\MarketProductExclusion;
use App\Models\Product;
use App\Models\ProductImage;
use App\Support\Money;
use App\Tenancy\CurrentStore;
use App\Testing\Experiments;
use App\Testing\TestAndLearn;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Products › Test-and-learn (测款): verdicts per product and market, search terms, A/B tests. */
class TestingController extends Controller
{
    public function index(Request $request, CurrentStore $current, TestAndLearn $tests): JsonResponse
    {
        $store = $current->getOrFail();
        $market = $request->query('market', 'all');
        $marketId = $market === 'all' ? null : (int) $market;
        $r = $tests->report($store, $marketId, $request->query('scope', 'new') !== 'all');
        $status = $request->query('status');

        return response()->json([
            'data' => $r['rows']->when(in_array($status, TestAndLearn::STATUSES, true), fn ($c) => $c->where('status', $status))->values()
                ->map(fn ($row) => [...$row, 'revenue' => Money::format($row['revenue'])]),
            'counts' => $r['rows']->countBy('status'),
            'baseline' => $r['baseline'], 'window_days' => $r['window_days'], 'from' => $r['from'],
            'settings' => TestAndLearn::settings($store),
            'store_currency' => $store->baseCurrency(),
            'markets' => Market::orderBy('position')->get(['id', 'name']),
            'collections' => Collection::orderBy('title')->get(['id', 'title', 'rules'])->filter(fn ($c) => empty($c->rules))->map->only('id', 'title')->values(),   // manual ones
            'experiments' => ContentExperiment::where('status', 'running')->count(),
        ]);
    }

    public function saveSettings(Request $request, CurrentStore $current): JsonResponse
    {
        $data = $request->validate([
            'window_days' => ['required', 'integer', 'min:3', 'max:90'],
            'min_views' => ['required', 'integer', 'min:5', 'max:10000'],
            'hot_multiplier' => ['required', 'numeric', 'min:1.1', 'max:10'],
        ]);
        TestAndLearn::saveSettings($current->getOrFail(), $data);

        return response()->json(['settings' => TestAndLearn::settings($current->getOrFail())]);
    }

    /** One product: numbers per market (where to push it next) and its A/B tests. */
    public function product(Product $product, CurrentStore $current, TestAndLearn $tests): JsonResponse
    {
        return response()->json(['data' => [
            'markets' => $tests->byMarket($current->getOrFail(), $product),
            'experiments' => $this->experimentsOf($product),
        ]]);
    }

    /** draft | archive | collection (add to one) | sell_everywhere (remove market exclusions) */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['draft', 'archive', 'collection', 'sell_everywhere'])],
            'collection_id' => ['required_if:action,collection', 'nullable', 'integer'],
        ]);
        $ids = Product::whereIn('id', $data['ids'])->pluck('id');   // this store's only
        $done = match ($data['action']) {
            'draft' => Product::whereIn('id', $ids)->update(['status' => 'draft']),
            'archive' => Product::whereIn('id', $ids)->update(['status' => 'archived']),
            'sell_everywhere' => MarketProductExclusion::whereIn('product_id', $ids)->delete() >= 0 ? $ids->count() : 0,
            'collection' => (function () use ($ids, $data) {
                $c = Collection::findOrFail($data['collection_id']);
                if (! empty($c->rules)) {
                    throw ValidationException::withMessages(['collection_id' => __('智能分类按条件自动选品，不能手动加入商品')]);
                }
                $max = (int) DB::table('collection_product')->where('collection_id', $c->id)->max('position');
                foreach ($ids as $i => $id) {
                    DB::table('collection_product')->insertOrIgnore(['collection_id' => $c->id, 'product_id' => $id, 'store_id' => $c->store_id, 'position' => $max + $i + 1]);
                }

                return $ids->count();
            })(),
        };

        return response()->json(['done' => $done]);
    }

    /** What shoppers searched for, and what they searched for but didn't find. */
    public function searchTerms(Request $request, CurrentStore $current): JsonResponse
    {
        $store = $current->getOrFail();
        $days = max(1, min(365, $request->integer('days', 30)));
        $from = CarbonImmutable::now($store->timezone ?: 'UTC')->subDays($days)->toDateString();
        $market = $request->query('market', 'all');
        $base = DB::table('search_terms')->where('store_id', $store->id)->where('date', '>=', $from)
            ->when($market !== 'all', fn ($q) => $q->where('market_id', (int) $market));
        $markets = Market::pluck('name', 'id');
        $rows = fn ($q) => $q->groupBy('term')
            ->selectRaw('term, SUM(searches) as searches, SUM(zero_results) as zero_results, MAX(date) as last_date, GROUP_CONCAT(DISTINCT market_id) as market_ids')
            ->limit(50)->get()->map(fn ($r) => [
                'term' => $r->term, 'searches' => (int) $r->searches, 'zero_results' => (int) $r->zero_results, 'last_date' => $r->last_date,
                'markets' => collect(explode(',', (string) $r->market_ids))->map(fn ($id) => (int) $id === 0 ? __('默认站点') : ($markets[(int) $id] ?? '—'))->unique()->values(),
            ]);

        return response()->json([
            'top' => $rows((clone $base)->orderByRaw('SUM(searches) DESC')),
            'no_results' => $rows((clone $base)->havingRaw('SUM(zero_results) > 0')->orderByRaw('SUM(zero_results) DESC')),
            'total' => (int) (clone $base)->sum('searches'),
            'zero' => (int) (clone $base)->sum('zero_results'),
        ]);
    }

    public function startExperiment(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['image', 'title'])],
            'b_value' => ['required', 'string', 'max:255'],
        ]);
        if (ContentExperiment::where('product_id', $product->id)->where('status', 'running')->exists()) {
            throw ValidationException::withMessages(['kind' => __('这个商品已经有一个正在进行的测试')]);
        }
        if ($data['kind'] === 'image') {
            $images = ProductImage::where('product_id', $product->id)->orderBy('position')->orderBy('id')->pluck('id');
            if (! $images->contains((int) $data['b_value']) || (int) $data['b_value'] === $images->first()) {
                throw ValidationException::withMessages(['b_value' => __('请选择这个商品的另一张图片（不能是现在的主图）')]);
            }
        } elseif (trim($data['b_value']) === trim((string) $product->getRawOriginal('title'))) {
            throw ValidationException::withMessages(['b_value' => __('新标题和现在的标题一样')]);
        }
        ContentExperiment::create([...$data, 'b_value' => trim($data['b_value']), 'product_id' => $product->id, 'status' => 'running', 'started_at' => now()]);

        return response()->json(['data' => $this->experimentsOf($product)], 201);
    }

    /** winner a | b (B becomes the real title / main image), or null to stop without a winner. */
    public function finishExperiment(Request $request, ContentExperiment $experiment, Experiments $experiments): JsonResponse
    {
        $data = $request->validate(['winner' => ['nullable', Rule::in(['a', 'b'])]]);
        abort_if($experiment->status !== 'running', 422, __('这个测试已经结束'));
        $experiments->finish($experiment, $data['winner'] ?? null);

        return response()->json(['data' => $this->experimentsOf(Product::findOrFail($experiment->product_id))]);
    }

    private function experimentsOf(Product $product): array
    {
        $images = ProductImage::where('product_id', $product->id)->get()->keyBy('id');

        return ContentExperiment::where('product_id', $product->id)->latest('id')->limit(10)->get()->map(fn (ContentExperiment $e) => [
            'id' => $e->id, 'kind' => $e->kind, 'status' => $e->status, 'winner' => $e->winner,
            'a' => $e->kind === 'title' ? $product->getRawOriginal('title') : null,
            'b' => $e->kind === 'title' ? $e->b_value : $images->get((int) $e->b_value)?->url(),
            'a_views' => $e->a_views, 'a_carts' => $e->a_carts, 'b_views' => $e->b_views, 'b_carts' => $e->b_carts,
            'started_at' => $e->started_at, 'ended_at' => $e->ended_at, 'stats' => Experiments::stats($e), 'min_views' => Experiments::MIN_VIEWS,
        ])->all();
    }
}
