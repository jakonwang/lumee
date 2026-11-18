<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Tenancy\CurrentStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Monthly customer cohorts: buyers grouped by the month of their first paid order (store time zone),
 * then the share of each cohort that bought again in each following month. A buyer is identified
 * by the order email, so guest checkouts count too.
 */
class RetentionController extends Controller
{
    public function __invoke(Request $request, CurrentStore $current): JsonResponse
    {
        $store = $current->getOrFail();
        $tz = $store->timezone ?: 'UTC';
        $months = in_array((int) $request->query('months'), [6, 12], true) ? (int) $request->query('months') : 6;
        $first = CarbonImmutable::now($tz)->startOfMonth()->subMonths($months - 1);

        // Every paid order per buyer (all time, so "first order" is really the first).
        $byEmail = Order::whereIn('financial_status', Order::PAID_STATUSES)->whereNull('cancelled_at')
            ->whereNotNull('email')->orderBy('created_at')->get(['email', 'created_at'])
            ->groupBy(fn ($o) => mb_strtolower(trim($o->email)));

        $index = fn (CarbonImmutable $t) => ($t->year - $first->year) * 12 + $t->month - $first->month;
        $cohorts = [];
        for ($i = 0; $i < $months; $i++) {
            $cohorts[$i] = ['month' => $first->addMonths($i)->format('Y-m'), 'customers' => 0, 'active' => array_fill(0, $months - $i, 0)];
        }

        $customers = $repeaters = $orders = 0;
        $gaps = [];
        foreach ($byEmail as $list) {
            $times = $list->map(fn ($o) => CarbonImmutable::parse($o->created_at)->setTimezone($tz))->values();
            $customers++;
            $orders += $times->count();
            if ($times->count() > 1) {
                $repeaters++;
                $gaps[] = $times[0]->diffInDays($times[1], true);
            }
            $c = $index($times[0]);
            if ($c < 0) {
                continue; // first bought before the window
            }
            $cohorts[$c]['customers']++;
            $seen = [];
            foreach ($times as $t) {
                $offset = $index($t) - $c;
                if (! isset($seen[$offset]) && isset($cohorts[$c]['active'][$offset])) {
                    $seen[$offset] = true;
                    $cohorts[$c]['active'][$offset]++;
                }
            }
        }

        sort($gaps);
        $median = $gaps ? $gaps[intdiv(count($gaps), 2)] : null;

        return response()->json([
            'months' => $months,
            'summary' => [
                'customers' => $customers,
                'repeat_customers' => $repeaters,
                'repeat_rate' => $customers ? round($repeaters / $customers * 100, 1) : 0,
                'orders_per_customer' => $customers ? round($orders / $customers, 2) : 0,
                'median_days_to_second' => $median === null ? null : (int) round($median),
            ],
            'cohorts' => collect($cohorts)->map(fn ($c) => [
                'month' => $c['month'],
                'customers' => $c['customers'],
                // month 0 is always 100%; later months = share of the cohort that bought in that month
                'retention' => collect($c['active'])->map(fn ($n) => $c['customers'] ? round($n / $c['customers'] * 100, 1) : null)->values(),
            ])->reverse()->values(),
        ]);
    }
}
