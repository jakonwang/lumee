<?php

namespace App\Testing;

use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Test-and-learn (测款): how each product performs in its first weeks — per market or overall —
 * against the store's own average, and what to do next:
 *   hot         enough views and an add-to-cart rate ≥ N× the store average, or several orders → restock, promote, try other markets
 *   cold        tested long enough with enough views, no orders and a weak add-to-cart rate → new main image / price, or retire it
 *   no_traffic  online for days with almost no views → title/SEO, homepage, ads
 *   testing     still collecting data
 * Settings: stores.settings['testing'] = {window_days, min_views, hot_multiplier}.
 */
class TestAndLearn
{
    public const STATUSES = ['hot', 'testing', 'cold', 'no_traffic'];

    public static function settings(Store $store): array
    {
        $s = (array) ($store->settings['testing'] ?? []);

        return [
            'window_days' => max(3, min(90, (int) ($s['window_days'] ?? 14))),
            'min_views' => max(5, min(10000, (int) ($s['min_views'] ?? 50))),
            'hot_multiplier' => max(1.1, min(10, (float) ($s['hot_multiplier'] ?? 2.0))),
        ];
    }

    public static function saveSettings(Store $store, array $data): void
    {
        $settings = $store->settings ?? [];
        $settings['testing'] = array_intersect_key($data, array_flip(['window_days', 'min_views', 'hot_multiplier']));
        $store->settings = $settings;
        $store->save();
    }

    /**
     * Products with their numbers over the test window (newest products, or all active ones).
     *
     * @param  int|null  $marketId  null = every market together; 0 = the default site
     * @return array{rows: Collection, baseline: float, window_days: int, from: string}
     */
    public function report(Store $store, ?int $marketId = null, bool $newOnly = true): array
    {
        $s = self::settings($store);
        $from = CarbonImmutable::now($store->timezone ?: 'UTC')->subDays($s['window_days'])->startOfDay();
        $products = Product::query()->where('status', 'active')
            // New products: launched within two test windows, so ones at the end of their test are still judged
            ->when($newOnly, fn ($q) => $q->where('created_at', '>=', $from->subDays($s['window_days'])))
            ->with(['images' => fn ($q) => $q->orderBy('position')->limit(1)])
            ->get(['id', 'title', 'created_at', 'status', 'test_status']);
        $ids = $products->pluck('id');

        $traffic = DB::table('product_market_stats')->where('store_id', $store->id)->whereIn('product_id', $ids)
            ->where('date', '>=', $from->toDateString())
            ->when($marketId !== null, fn ($q) => $q->where('market_id', $marketId))
            ->groupBy('product_id')->selectRaw('product_id, SUM(views) as views, SUM(carts) as carts')->get()->keyBy('product_id');

        $sales = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.store_id', $store->id)->whereIn('order_items.product_id', $ids)
            ->whereIn('orders.financial_status', Order::PAID_STATUSES)->where('orders.created_at', '>=', $from)
            ->when($marketId !== null, fn ($q) => $marketId === 0 ? $q->whereNull('orders.market_id') : $q->where('orders.market_id', $marketId))
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id, COUNT(DISTINCT order_items.order_id) as orders, SUM(order_items.quantity) as units,
                SUM(order_items.refunded_quantity) as refunded, SUM(order_items.total / orders.exchange_rate) as revenue')
            ->get()->keyBy('product_id');

        // The store's own add-to-cart rate over the same period (all products, same market filter)
        $all = DB::table('product_market_stats')->where('store_id', $store->id)->where('date', '>=', $from->toDateString())
            ->when($marketId !== null, fn ($q) => $q->where('market_id', $marketId))
            ->selectRaw('COALESCE(SUM(views),0) as views, COALESCE(SUM(carts),0) as carts')->first();
        $baseline = $all->views ? $all->carts / $all->views : 0.0;

        $today = CarbonImmutable::now();
        $rows = $products->map(function (Product $p) use ($traffic, $sales, $baseline, $s, $today) {
            $t = $traffic->get($p->id);
            $o = $sales->get($p->id);
            $views = (int) ($t->views ?? 0);
            $carts = (int) ($t->carts ?? 0);
            $orders = (int) ($o->orders ?? 0);
            $units = (int) ($o->units ?? 0);
            $age = (int) $p->created_at->diffInDays($today);
            $rate = $views ? $carts / $views : 0.0;
            $status = $this->classify($views, $rate, $orders, $age, $baseline, $s);

            return [
                'id' => $p->id, 'title' => $p->title, 'image' => $p->images->first()?->url(), 'age_days' => $age,
                'views' => $views, 'carts' => $carts, 'cart_rate' => round($rate * 100, 1),
                'orders' => $orders, 'units' => $units, 'conversion' => $views ? round($orders / $views * 100, 2) : 0.0,
                'refund_rate' => $units ? round(((int) ($o->refunded ?? 0)) / $units * 100, 1) : 0.0,
                'revenue' => (int) round((float) ($o->revenue ?? 0)),
                'status' => $status, 'advice' => self::advice($status),
            ];
        })->sortBy(fn ($r) => [array_search($r['status'], self::STATUSES, true), -$r['views']])->values();

        return ['rows' => $rows, 'baseline' => round($baseline * 100, 2), 'window_days' => $s['window_days'], 'from' => $from->toDateString()];
    }

    public function classify(int $views, float $rate, int $orders, int $age, float $baseline, array $s): string
    {
        $enough = $views >= $s['min_views'];
        // With no store data yet, 5% is a sensible add-to-cart benchmark for a new product
        $bench = $baseline > 0 ? $baseline : 0.05;

        return match (true) {
            $orders >= 3 || ($enough && $rate >= $bench * $s['hot_multiplier']) => 'hot',
            $enough && $age >= $s['window_days'] && $orders === 0 && $rate < $bench * 0.5 => 'cold',
            $age >= 3 && $views < max(5, intdiv($s['min_views'], 5)) => 'no_traffic',
            default => 'testing',
        };
    }

    /** @return string[] */
    public static function advice(string $status): array
    {
        return match ($status) {
            'hot' => ['确认库存，准备补货', '放到首页或爆款分类，加大广告投放', '在还没卖这款的市场上架试试'],
            'cold' => ['换一张主图，或开一个主图 A/B 测试', '检查价格是否偏高', '仍然不行就下架，把位置让给新品'],
            'no_traffic' => ['检查标题和 SEO 是否写清楚', '放到首页、分类或邮件里，给它一些曝光'],
            default => ['继续收集数据'],
        };
    }

    /** Views / carts / orders of one product in every market (where to push it next). */
    public function byMarket(Store $store, Product $product): Collection
    {
        $s = self::settings($store);
        $from = CarbonImmutable::now($store->timezone ?: 'UTC')->subDays($s['window_days'])->startOfDay();
        $traffic = DB::table('product_market_stats')->where('store_id', $store->id)->where('product_id', $product->id)->where('date', '>=', $from->toDateString())
            ->groupBy('market_id')->selectRaw('market_id, SUM(views) as views, SUM(carts) as carts')->get()->keyBy('market_id');
        $sales = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.store_id', $store->id)->where('order_items.product_id', $product->id)
            ->whereIn('orders.financial_status', Order::PAID_STATUSES)->where('orders.created_at', '>=', $from)
            ->groupBy('orders.market_id')->selectRaw('orders.market_id, COUNT(DISTINCT orders.id) as orders')->get()
            ->mapWithKeys(fn ($r) => [(int) $r->market_id => (int) $r->orders]);
        $excluded = DB::table('market_product_exclusions')->where('product_id', $product->id)->pluck('market_id')->flip();

        return collect([['id' => 0, 'name' => __('默认站点')]])
            ->concat(\App\Models\Market::orderBy('position')->get(['id', 'name'])->map(fn ($m) => ['id' => $m->id, 'name' => $m->name]))
            ->map(fn ($m) => [...$m, 'sold' => ! $excluded->has($m['id']),
                'views' => (int) ($traffic->get($m['id'])->views ?? 0), 'carts' => (int) ($traffic->get($m['id'])->carts ?? 0),
                'orders' => (int) ($sales[$m['id']] ?? 0)]);
    }

    /** Daily: remember each active product's verdict (overall) so lists and smart filters can use it. */
    public function evaluate(Store $store): int
    {
        $r = $this->report($store, null, false);
        foreach ($r['rows'] as $row) {
            Product::whereKey($row['id'])->update(['test_status' => $row['status'], 'test_evaluated_at' => now()]);
        }

        return $r['rows']->count();
    }
}
