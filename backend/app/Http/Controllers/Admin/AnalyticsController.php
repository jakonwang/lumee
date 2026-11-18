<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Support\Countries;
use App\Support\Money;
use App\Tenancy\CurrentStore;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Store analytics for a date range in the store's time zone, each headline compared with the
 * previous period of equal length.
 *
 * - Sales: "revenue" is net sales of paid orders (captured minus refunded); pending bank transfers aren't money yet.
 * - Traffic: visits recorded by App\Analytics\Visits (bots ignored). A visit "converts" when the order
 *   placed in it is paid. Funnel = visits → viewed a product → added to cart → reached checkout → purchased.
 */
class AnalyticsController extends Controller
{
    public const MAX_DAYS = 366;

    private const FUNNEL = ['sessions' => null, 'product' => 'viewed_product', 'cart' => 'added_to_cart', 'checkout' => 'reached_checkout', 'purchase' => 'purchase'];

    private int $storeId;

    public function __invoke(Request $request, CurrentStore $current): JsonResponse
    {
        $store = $current->getOrFail();
        $this->storeId = $store->id;
        $tz = $store->timezone ?: 'UTC';
        [$start, $end] = $this->range($request, $tz);
        $days = max(1, (int) round($start->diffInDays($end->addSecond(), true)));
        $prevEnd = $start->subSecond();
        $prevStart = $start->subDays($days);
        $hourly = $days === 1;

        // ---- Sales -----------------------------------------------------------------------
        $orders = Order::where('created_at', '>=', $prevStart->utc())->where('created_at', '<=', $end->utc())
            ->where('financial_status', '!=', 'voided')->whereNull('cancelled_at')
            ->get(['id', 'email', 'created_at', 'total', 'refunded_total', 'financial_status', 'discount_total', 'shipping_address']);
        [$cur, $prev] = $orders->partition(fn ($o) => $o->created_at->gte($start->utc()));
        $paid = fn (Collection $c) => $c->whereIn('financial_status', Order::PAID_STATUSES);
        $net = fn (Collection $c) => (int) $c->sum(fn ($o) => $o->total - $o->refunded_total);
        $metrics = fn (Collection $c) => [
            'revenue' => $net($paid($c)),
            'orders' => $c->count(),
            'aov' => $paid($c)->count() ? (int) round($net($paid($c)) / $paid($c)->count()) : 0,
        ];
        $now = $metrics($cur);
        $before = $metrics($prev);
        $newCustomers = Customer::whereBetween('created_at', [$start->utc(), $end->utc()])->count();
        $prevCustomers = Customer::whereBetween('created_at', [$prevStart->utc(), $prevEnd->utc()])->count();

        // ---- Traffic ---------------------------------------------------------------------
        $visits = $this->visitTotals($start, $end);
        $prevVisits = $this->visitTotals($prevStart, $prevEnd);
        $conversion = fn (array $v) => $v['sessions'] ? round($v['purchase'] / $v['sessions'] * 100, 2) : 0.0;

        // ---- Time series (current + previous period, aligned by position) ------------------
        $bucket = fn (CarbonImmutable $t) => $hourly ? (int) $t->setTimezone($tz)->format('G') : (int) round($t->setTimezone($tz)->startOfDay()->diffInDays($start->startOfDay(), true));
        $prevBucket = fn (CarbonImmutable $t) => $hourly ? (int) $t->setTimezone($tz)->format('G') : (int) round($t->setTimezone($tz)->startOfDay()->diffInDays($prevStart->startOfDay(), true));
        $n = $hourly ? 24 : $days;
        $series = array_fill(0, $n, null);
        $blank = ['revenue' => 0, 'orders' => 0, 'sessions' => 0, 'prev_revenue' => 0, 'prev_orders' => 0, 'prev_sessions' => 0];
        foreach ($series as $i => $_) {
            $series[$i] = $blank;
        }
        foreach ($cur as $o) {
            $i = $bucket(CarbonImmutable::parse($o->created_at));
            if (isset($series[$i])) {
                $series[$i]['orders']++;
                $series[$i]['revenue'] += in_array($o->financial_status, Order::PAID_STATUSES, true) ? $o->total - $o->refunded_total : 0;
            }
        }
        foreach ($prev as $o) {
            $i = $prevBucket(CarbonImmutable::parse($o->created_at));
            if (isset($series[$i])) {
                $series[$i]['prev_orders']++;
                $series[$i]['prev_revenue'] += in_array($o->financial_status, Order::PAID_STATUSES, true) ? $o->total - $o->refunded_total : 0;
            }
        }
        foreach ($this->sessions($start, $end)->pluck('started_at') as $t) {
            $i = $bucket(CarbonImmutable::parse($t, 'UTC'));
            isset($series[$i]) && $series[$i]['sessions']++;
        }
        foreach ($this->sessions($prevStart, $prevEnd)->pluck('started_at') as $t) {
            $i = $prevBucket(CarbonImmutable::parse($t, 'UTC'));
            isset($series[$i]) && $series[$i]['prev_sessions']++;
        }
        $series = collect($series)->map(fn ($r, $i) => [
            'key' => $hourly ? sprintf('%02d:00', $i) : $start->addDays($i)->toDateString(),
            ...$r,
            'revenue' => Money::format($r['revenue']),
            'prev_revenue' => Money::format($r['prev_revenue']),
        ])->values();

        // Orders by hour of day (when do buyers shop?)
        $hours = array_fill(0, 24, 0);
        foreach ($cur as $o) {
            $hours[(int) $o->created_at->setTimezone($tz)->format('G')]++;
        }

        return response()->json([
            'currency' => $store->currency,
            'timezone' => $tz,
            'range' => [$start->toDateString(), $end->toDateString()],
            'previous' => [$prevStart->toDateString(), $prevEnd->toDateString()],
            'granularity' => $hourly ? 'hour' : 'day',
            'totals' => [
                'revenue' => $this->stat(Money::format($now['revenue']), $now['revenue'], $before['revenue']),
                'orders' => $this->stat($now['orders'], $now['orders'], $before['orders']),
                'aov' => $this->stat(Money::format($now['aov']), $now['aov'], $before['aov']),
                'visitors' => $this->stat($visits['visitors'], $visits['visitors'], $prevVisits['visitors']),
                'sessions' => $this->stat($visits['sessions'], $visits['sessions'], $prevVisits['sessions']),
                'conversion' => $this->stat($conversion($visits), (int) round($conversion($visits) * 100), (int) round($conversion($prevVisits) * 100)),
                'customers' => $this->stat($newCustomers, $newCustomers, $prevCustomers),
            ],
            'discounted_orders' => $cur->where('discount_total', '>', 0)->count(),
            'pending_orders' => $cur->where('financial_status', 'pending')->count(),
            'series' => $series,
            'hours' => $hours,
            'funnel' => collect(self::FUNNEL)->keys()->map(fn ($k) => ['key' => $k, 'sessions' => $visits[$k], 'prev' => $prevVisits[$k]])->values(),
            'sources' => $this->breakdown('source', $start, $end),
            'devices' => $this->breakdown('device', $start, $end),
            'browsers' => $this->breakdown('browser', $start, $end, 6),
            'landing_pages' => $this->breakdown('landing_path', $start, $end, 10),
            'referrers' => $this->breakdown('referrer_host', $start, $end, 8),
            'campaigns' => $this->breakdown('utm_campaign', $start, $end, 8),
            'countries' => $this->countries($paid($cur), $start, $end),
            'products' => $this->products($paid($cur)->pluck('id'), $start, $end),
            'customers' => $this->customerMix($paid($cur)),
            'returning_visitors' => (int) $this->sessions($start, $end)->where('returning', true)->distinct()->count('visitor'),
            'live' => DB::table('analytics_sessions')->where('store_id', $this->storeId)->where('last_seen_at', '>=', now()->subMinutes(5))->count(),
        ]);
    }

    /** from/to (Y-m-d) in the store's time zone, or days=N ending today. */
    private function range(Request $request, string $tz): array
    {
        $today = CarbonImmutable::now($tz);
        if ($request->filled('from') || $request->filled('to')) {
            $data = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
            $start = CarbonImmutable::createFromFormat('Y-m-d', $data['from'], $tz)->startOfDay();
            $end = CarbonImmutable::createFromFormat('Y-m-d', $data['to'], $tz)->endOfDay();
            if ($start->diffInDays($end) >= self::MAX_DAYS) {
                throw ValidationException::withMessages(['from' => __('时间范围最长 :p0 天', ['p0' => self::MAX_DAYS])]);
            }
            if ($end->gt($today->endOfDay())) {
                $end = $today->endOfDay();
            }

            return [$start, max($start->endOfDay(), $end)];
        }
        $days = in_array((int) $request->query('days'), [1, 7, 30, 90], true) ? (int) $request->query('days') : 30;

        return [$today->subDays($days - 1)->startOfDay(), $today->endOfDay()];
    }

    private function sessions(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return DB::table('analytics_sessions')->where('analytics_sessions.store_id', $this->storeId)
            ->whereBetween('analytics_sessions.started_at', [$from->utc(), $to->utc()]);
    }

    /** Visits joined to their order when that order is paid (= a purchase). */
    private function withPurchases(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        $paid = "'".implode("','", Order::PAID_STATUSES)."'";

        return $this->sessions($from, $to)
            ->leftJoin('orders', fn ($j) => $j->on('orders.id', '=', 'analytics_sessions.order_id')->whereRaw("orders.financial_status in ($paid)"));
    }

    private function visitTotals(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $r = $this->withPurchases($from, $to)->selectRaw(
            'COUNT(*) as sessions, COUNT(DISTINCT analytics_sessions.visitor) as visitors,
             SUM(CASE WHEN viewed_product = 1 THEN 1 ELSE 0 END) as product,
             SUM(CASE WHEN added_to_cart = 1 THEN 1 ELSE 0 END) as cart,
             SUM(CASE WHEN reached_checkout = 1 THEN 1 ELSE 0 END) as checkout,
             COUNT(orders.id) as purchase'
        )->first();

        return collect((array) $r)->map(fn ($v) => (int) $v)->all();
    }

    /** Visits, purchases, sales and conversion grouped by one visit attribute. */
    private function breakdown(string $column, CarbonImmutable $from, CarbonImmutable $to, int $limit = 0): Collection
    {
        $q = $this->withPurchases($from, $to)
            ->selectRaw("analytics_sessions.{$column} as k, COUNT(*) as sessions, COUNT(orders.id) as purchases, COALESCE(SUM(orders.total - orders.refunded_total), 0) as revenue")
            ->groupBy("analytics_sessions.{$column}")->orderByDesc('sessions');
        if (in_array($column, ['referrer_host', 'utm_campaign'], true)) {
            $q->whereNotNull("analytics_sessions.{$column}");
        }
        $limit && $q->limit($limit);

        return $q->get()->map(fn ($r) => [
            'key' => $r->k, 'sessions' => (int) $r->sessions, 'purchases' => (int) $r->purchases,
            'revenue' => Money::format((int) $r->revenue),
            'conversion' => $r->sessions ? round($r->purchases / $r->sessions * 100, 2) : 0,
        ])->values();
    }

    /** Visitors by visit country, sales by shipping country. */
    private function countries(Collection $paidOrders, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $visits = $this->sessions($from, $to)->selectRaw('country, COUNT(*) as n')->groupBy('country')->pluck('n', 'country');
        $sales = $paidOrders->groupBy(fn ($o) => $o->shipping_address['country'] ?? null);
        $total = max(1, (int) $paidOrders->sum(fn ($o) => $o->total - $o->refunded_total));
        $codes = $visits->keys()->merge($sales->keys())->map(fn ($c) => $c ?: '')->unique();

        return $codes->map(function ($code) use ($visits, $sales, $total) {
            $orders = $sales->get($code, collect());
            $revenue = (int) $orders->sum(fn ($o) => $o->total - $o->refunded_total);

            return [
                'code' => $code ?: null,
                'name' => $code ? (Countries::all()[$code] ?? $code) : __('未知'),
                'sessions' => (int) ($visits[$code] ?? 0),
                'orders' => $orders->count(),
                'revenue_minor' => $revenue,
                'revenue' => Money::format($revenue),
                'share' => round($revenue / $total * 100, 1),
            ];
        })->sortByDesc(fn ($c) => [$c['revenue_minor'], $c['sessions']])->take(15)
            ->map(fn ($c) => collect($c)->except('revenue_minor')->all())->values();
    }

    /** Best sellers plus most viewed: views, add-to-carts, units, sales. */
    private function products(Collection $paidIds, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $sales = $paidIds->isEmpty() ? collect() : DB::table('order_items')->whereIn('order_id', $paidIds)
            ->groupBy('product_id', 'title')
            ->selectRaw('product_id, title, SUM(quantity - refunded_quantity) as qty, SUM(total - price * refunded_quantity) as revenue, COUNT(DISTINCT order_id) as orders')
            ->get();
        $stats = DB::table('analytics_product_stats')->where('analytics_product_stats.store_id', $this->storeId)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->join('products', 'products.id', '=', 'analytics_product_stats.product_id')
            ->groupBy('analytics_product_stats.product_id', 'products.title')
            ->selectRaw('analytics_product_stats.product_id, products.title, SUM(views) as views, SUM(carts) as carts')
            ->get()->keyBy('product_id');

        $rows = collect();
        foreach ($sales as $s) {
            $key = $s->product_id ?: 't:'.$s->title;
            $prev = $rows->get($key);
            $rows->put($key, [
                'id' => $s->product_id, 'title' => $prev['title'] ?? $s->title,
                'qty' => ($prev['qty'] ?? 0) + (int) $s->qty, 'orders' => ($prev['orders'] ?? 0) + (int) $s->orders,
                'revenue' => ($prev['revenue'] ?? 0) + (int) $s->revenue,
            ]);
        }
        foreach ($stats as $id => $st) {
            $rows->put($id, ['id' => $id, 'title' => $rows->get($id)['title'] ?? $st->title, 'qty' => 0, 'orders' => 0, 'revenue' => 0, ...($rows->get($id) ?? [])]);
        }

        return $rows->map(function ($r) use ($stats) {
            $st = $r['id'] ? $stats->get($r['id']) : null;
            $views = (int) ($st->views ?? 0);
            $carts = (int) ($st->carts ?? 0);

            return [
                ...$r, 'views' => $views, 'carts' => $carts,
                'cart_rate' => $views ? round(min($carts, $views) / $views * 100, 1) : null,
                'conversion' => $views ? round(min($r['orders'], $views) / $views * 100, 1) : null,
            ];
        })->sortByDesc(fn ($r) => [$r['revenue'], $r['views']])->take(10)
            ->map(fn ($r) => [...$r, 'revenue' => Money::format($r['revenue'])])->values();
    }

    /** First-time vs returning buyers (by email) among paid orders in the period. */
    private function customerMix(Collection $paidOrders): array
    {
        $ids = $paidOrders->pluck('id');
        $returningIds = $ids->isEmpty() ? collect() : Order::whereIn('orders.id', $ids)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('orders as earlier')
                ->whereColumn('earlier.store_id', 'orders.store_id')->whereColumn('earlier.email', 'orders.email')
                ->whereColumn('earlier.id', '<', 'orders.id')->where('earlier.financial_status', '!=', 'voided'))
            ->pluck('orders.id')->flip();
        [$returning, $first] = $paidOrders->partition(fn ($o) => $returningIds->has($o->id));
        $sum = fn (Collection $c) => (int) $c->sum(fn ($o) => $o->total - $o->refunded_total);

        return [
            'new' => ['orders' => $first->count(), 'revenue' => Money::format($sum($first))],
            'returning' => ['orders' => $returning->count(), 'revenue' => Money::format($sum($returning))],
        ];
    }

    /** Value + change vs. previous period (null when there's nothing to compare against). */
    private function stat(int|float|string $display, int $now, int $before): array
    {
        return [
            'value' => $display,
            'change' => $before > 0 ? round(($now - $before) / $before * 100, 1) : null,
        ];
    }
}
