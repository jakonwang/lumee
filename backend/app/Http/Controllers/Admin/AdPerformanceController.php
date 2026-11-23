<?php

namespace App\Http\Controllers\Admin;

use App\Analytics\AdReport;
use App\Analytics\AdSpendImport;
use App\Currency\ExchangeRates;
use App\Http\Controllers\Controller;
use App\Models\AdSpend;
use App\Models\Market;
use App\Support\Money;
use App\Tenancy\CurrentStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Analytics › Ad performance: spend vs orders and revenue per platform, market and campaign; spend import and entry. */
class AdPerformanceController extends Controller
{
    public const MAX_DAYS = 366;

    public function report(Request $request, CurrentStore $current, AdReport $report): JsonResponse
    {
        $store = $current->getOrFail();
        $tz = $store->timezone ?: 'UTC';
        $today = CarbonImmutable::now($tz);
        if ($request->filled('from') || $request->filled('to')) {
            $data = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
            $from = CarbonImmutable::createFromFormat('Y-m-d', $data['from'], $tz)->startOfDay();
            $to = CarbonImmutable::createFromFormat('Y-m-d', $data['to'], $tz)->endOfDay();
            if ($from->diffInDays($to) >= self::MAX_DAYS) {
                throw ValidationException::withMessages(['from' => __('时间范围最长 :p0 天', ['p0' => self::MAX_DAYS])]);
            }
        } else {
            $days = in_array((int) $request->query('days'), [7, 30, 90], true) ? (int) $request->query('days') : 30;
            [$from, $to] = [$today->subDays($days - 1)->startOfDay(), $today->endOfDay()];
        }
        $r = $report->build($store, $from, $to);
        $fmt = fn (array $row) => [...$row,
            'spend' => Money::format($row['spend']), 'revenue' => Money::format($row['revenue']), 'organic_revenue' => Money::format($row['organic_revenue']),
            'cpa' => $row['cpa'] === null ? null : Money::format($row['cpa']), 'cpc' => $row['cpc'] === null ? null : Money::format($row['cpc'])];

        return response()->json([
            'currency' => $store->baseCurrency(),
            'range' => [$from->toDateString(), $to->toDateString()],
            'totals' => $fmt($r['totals']),
            'platforms' => array_map($fmt, $r['platforms']),
            'markets' => array_map($fmt, $r['markets']),
            'campaigns' => array_map($fmt, $r['campaigns']),
            'insights' => $r['insights'],
            'has_spend' => $r['has_spend'],
            'spend_lines' => AdSpend::latest('date')->latest('id')->limit(100)->get()->map(fn (AdSpend $s) => [
                'id' => $s->id, 'date' => substr((string) $s->date, 0, 10), 'platform' => $s->platform, 'market_id' => $s->market_id, 'campaign' => $s->campaign,
                'spend' => Money::format($s->spend), 'currency' => $s->currency, 'amount' => Money::format($s->amount), 'source' => $s->source,
            ]),
            'markets_list' => Market::orderBy('position')->get(['id', 'name']),
            'platform_labels' => AdReport::LABELS,
        ]);
    }

    /** The platform's CSV export, uploaded or pasted. */
    public function import(Request $request, CurrentStore $current, AdSpendImport $import): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['required', Rule::in(AdSpend::PLATFORMS)],
            'file' => ['nullable', 'file', 'max:5120'],
            'text' => ['nullable', 'string', 'max:2000000'],
        ]);
        $csv = $request->hasFile('file') ? (string) file_get_contents($request->file('file')->getRealPath()) : (string) ($data['text'] ?? '');
        if (trim($csv) === '') {
            throw ValidationException::withMessages(['file' => __('请上传或粘贴广告平台导出的报表')]);
        }
        try {
            return response()->json($import->import($current->getOrFail(), $data['platform'], $csv));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }
    }

    /** One line typed in: a day (or the first day of a period), platform, optional market and campaign, the amount and its currency. */
    public function store(Request $request, CurrentStore $current, ExchangeRates $rates): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'platform' => ['required', Rule::in(AdSpend::PLATFORMS)],
            'market_id' => ['nullable', 'integer'],
            'campaign' => ['nullable', 'string', 'max:150'],
            'amount' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'currency' => ['required', 'string', 'size:3'],
        ]);
        $store = $current->getOrFail();
        if (isset($data['market_id'])) {
            Market::findOrFail($data['market_id']);   // this store's only
        }
        $currency = strtoupper($data['currency']);
        $rate = $currency === $store->baseCurrency() ? 1.0 : $rates->rate($currency, $store->baseCurrency());
        if ($rate === null) {
            throw ValidationException::withMessages(['currency' => __('没有这个货币的汇率')]);
        }
        $amount = Money::toMinor($data['amount']);
        AdSpend::updateOrCreate(
            ['date' => $data['date'], 'platform' => $data['platform'], 'market_id' => $data['market_id'] ?? null, 'campaign' => trim((string) ($data['campaign'] ?? ''))],
            ['currency' => $currency, 'amount' => $amount, 'spend' => (int) round($amount * $rate), 'source' => 'manual'],
        );

        return response()->json(['ok' => true], 201);
    }

    public function destroy(int $id): JsonResponse
    {
        AdSpend::findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }
}
