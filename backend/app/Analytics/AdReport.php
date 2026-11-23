<?php

namespace App\Analytics;

use App\Models\AdSpend;
use App\Models\Market;
use App\Models\Order;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Ad performance (广告效果): spend set against the visits, orders and revenue each ad platform brought,
 * per platform, market (country site) and campaign.
 *
 * Attribution is last non-direct click: an order belongs to the visit it was placed in; when that visit
 * was direct (typed the address, a bookmark), to the same visitor's most recent visit from somewhere else
 * in the LOOKBACK_DAYS before. Revenue = paid orders minus refunds, in the store currency.
 * Paid visits (source "paid": ad click ids or a paid utm_medium) count towards the platform's ads;
 * other visits from the same platform (a post, a profile link) are shown as organic.
 */
class AdReport
{
    public const LOOKBACK_DAYS = 7;

    public const LABELS = ['google' => 'Google', 'meta' => 'Meta', 'tiktok' => 'TikTok', 'bing' => 'Bing', 'pinterest' => 'Pinterest', 'snapchat' => 'Snapchat', 'other' => '其他'];

    /** @return array{totals: array, platforms: array, markets: array, campaigns: array, insights: string[], has_spend: bool} */
    public function build(Store $store, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $paid = "'".implode("','", Order::PAID_STATUSES)."'";
        $sessions = DB::table('analytics_sessions')->where('analytics_sessions.store_id', $store->id)->whereBetween('analytics_sessions.started_at', [$from->utc(), $to->utc()]);

        // Visits per platform / market / campaign, split paid vs organic
        $visits = (clone $sessions)->whereNotNull('ad_platform')
            ->selectRaw("ad_platform, market_id, utm_campaign, CASE WHEN source = 'paid' THEN 1 ELSE 0 END as paid, COUNT(*) as n")
            ->groupBy('ad_platform', 'market_id', 'utm_campaign', 'paid')->get();

        // Orders and where they came from
        $orders = (clone $sessions)->join('orders', 'orders.id', '=', 'analytics_sessions.order_id')->whereRaw("orders.financial_status in ($paid)")
            ->get(['analytics_sessions.visitor', 'analytics_sessions.started_at', 'analytics_sessions.source', 'analytics_sessions.ad_platform',
                'analytics_sessions.market_id', 'analytics_sessions.utm_campaign', 'orders.id as order_id',
                DB::raw('(orders.total - orders.refunded_total) / orders.exchange_rate as revenue')]);
        $credit = [];
        foreach ($orders as $o) {
            $src = $o;
            if ($o->source === 'direct') {
                $src = DB::table('analytics_sessions')->where('store_id', $store->id)->where('visitor', $o->visitor)->where('source', '!=', 'direct')
                    ->where('started_at', '<', $o->started_at)->where('started_at', '>=', CarbonImmutable::parse($o->started_at)->subDays(self::LOOKBACK_DAYS))
                    ->orderByDesc('started_at')->first(['source', 'ad_platform', 'utm_campaign']) ?? $o;
                $src->market_id = $o->market_id;   // the order's site
            }
            if (! $src->ad_platform) {
                continue;
            }
            $credit[] = ['platform' => $src->ad_platform, 'paid' => $src->source === 'paid', 'market_id' => $o->market_id,
                'campaign' => $src->utm_campaign, 'revenue' => (int) round((float) $o->revenue)];
        }

        $spend = AdSpend::whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('platform, market_id, campaign, SUM(spend) as spend, SUM(impressions) as impressions, SUM(clicks) as clicks')
            ->groupBy('platform', 'market_id', 'campaign')->get();

        // One shape for all three: platform, market_id, campaign + the numbers
        $visits = $visits->map(fn ($v) => ['platform' => $v->ad_platform, 'market_id' => $v->market_id, 'campaign' => $v->utm_campaign, 'paid' => (bool) $v->paid, 'n' => (int) $v->n])->all();
        $spend = $spend->map(fn ($s) => ['platform' => $s->platform, 'market_id' => $s->market_id, 'campaign' => $s->campaign,
            'spend' => (int) $s->spend, 'impressions' => (int) $s->impressions, 'clicks' => (int) $s->clicks])->all();

        $platforms = $this->group($visits, $credit, $spend, fn (array $r) => $r['platform']);
        $markets = $this->group($visits, $credit, $spend, fn (array $r) => (string) ($r['market_id'] ?? 0));
        $campaigns = $this->group($visits, $credit, $spend, fn (array $r) => mb_strtolower(trim((string) $r['campaign'])));
        unset($campaigns['']);

        $names = Market::pluck('name', 'id');
        $platformRows = collect($platforms)->map(fn ($v, $k) => ['key' => $k, 'label' => self::LABELS[$k] ?? $k, ...$v])->sortByDesc(fn ($r) => [$r['spend'], $r['paid_sessions']])->values()->all();
        $marketRows = collect($markets)->map(fn ($v, $k) => ['key' => (int) $k, 'label' => (int) $k ? ($names[(int) $k] ?? '—') : '默认站点 / 未区分', ...$v])->sortByDesc('spend')->values()->all();
        $campaignRows = collect($campaigns)->map(fn ($v, $k) => ['key' => $k, 'label' => $k, ...$v])->sortByDesc('spend')->take(30)->values()->all();
        $totals = $this->totals($platformRows);

        return [
            'totals' => $totals,
            'platforms' => $platformRows,
            'markets' => $marketRows,
            'campaigns' => $campaignRows,
            'insights' => $this->insights($totals, $platformRows, $marketRows, $campaignRows),
            'has_spend' => $spend !== [],
        ];
    }

    /** Sum visits, attributed orders and spend under one key. */
    private function group(array $visits, array $credit, array $spend, callable $key): array
    {
        $out = [];
        $row = fn () => ['spend' => 0, 'impressions' => 0, 'clicks' => 0, 'paid_sessions' => 0, 'organic_sessions' => 0, 'orders' => 0, 'revenue' => 0, 'organic_orders' => 0, 'organic_revenue' => 0];
        foreach ($visits as $v) {
            $k = $key($v);
            $out[$k] ??= $row();
            $out[$k][$v['paid'] ? 'paid_sessions' : 'organic_sessions'] += $v['n'];
        }
        foreach ($credit as $c) {
            $k = $key($c);
            $out[$k] ??= $row();
            $out[$k][$c['paid'] ? 'orders' : 'organic_orders']++;
            $out[$k][$c['paid'] ? 'revenue' : 'organic_revenue'] += $c['revenue'];
        }
        foreach ($spend as $s) {
            $k = $key($s);
            $out[$k] ??= $row();
            $out[$k]['spend'] += $s['spend'];
            $out[$k]['impressions'] += $s['impressions'];
            $out[$k]['clicks'] += $s['clicks'];
        }

        return array_map(fn ($r) => $r + self::ratios($r), $out);
    }

    /** ROAS = revenue / spend; CPA = spend per order; conversion = orders / paid visits; CPC = spend per click. */
    public static function ratios(array $r): array
    {
        return [
            'roas' => $r['spend'] > 0 ? round($r['revenue'] / $r['spend'], 2) : null,
            'cpa' => $r['spend'] > 0 && $r['orders'] > 0 ? (int) round($r['spend'] / $r['orders']) : null,
            'conversion' => $r['paid_sessions'] > 0 ? round($r['orders'] / $r['paid_sessions'] * 100, 2) : null,
            'cpc' => $r['spend'] > 0 && $r['clicks'] > 0 ? (int) round($r['spend'] / $r['clicks']) : null,
        ];
    }

    private function totals(array $rows): array
    {
        $t = ['spend' => 0, 'impressions' => 0, 'clicks' => 0, 'paid_sessions' => 0, 'organic_sessions' => 0, 'orders' => 0, 'revenue' => 0, 'organic_orders' => 0, 'organic_revenue' => 0];
        foreach ($rows as $r) {
            foreach ($t as $k => $_) {
                $t[$k] += $r[$k];
            }
        }

        return $t + self::ratios($t);
    }

    /** Plain-language findings, most important first. @return string[] */
    private function insights(array $t, array $platforms, array $markets, array $campaigns): array
    {
        $out = [];
        if ($t['spend'] === 0) {
            $out[] = $t['paid_sessions'] > 0
                ? '已经有广告带来的访问，但还没有导入广告花费：导入后才能算出 ROAS 和每单成本。'
                : '还没有广告数据。导入广告平台的花费报表，并在广告链接上加 UTM 参数（utm_source / utm_medium=cpc / utm_campaign）。';

            return $out;
        }
        foreach ($campaigns as $c) {
            if ($c['spend'] > 0 && $c['orders'] === 0 && $c['spend'] >= max(1, $t['spend']) * 0.1) {
                $out[] = "广告系列「{$c['label']}」花了 ".self::money($c['spend'])."，还没有带来订单：检查落地页和受众，或者暂停。";
            }
        }
        foreach ($markets as $m) {
            if ($m['key'] && $m['spend'] > 0 && $m['roas'] !== null && $m['roas'] < 1) {   // a country site, not spend that isn't split by country
                $out[] = "「{$m['label']}」的 ROAS 只有 {$m['roas']}（花得比赚得多）：降低出价，或把预算移到效果好的市场。";
            }
        }
        $withSpend = array_values(array_filter($platforms, fn ($p) => $p['spend'] > 0 && $p['roas'] !== null));
        if (count($withSpend) >= 2) {
            usort($withSpend, fn ($a, $b) => $b['roas'] <=> $a['roas']);
            $best = $withSpend[0];
            $worst = end($withSpend);
            if ($best['roas'] >= 2 * max(0.01, $worst['roas'])) {
                $out[] = "{$best['label']} 的 ROAS（{$best['roas']}）是 {$worst['label']}（{$worst['roas']}）的两倍以上：可以把部分预算从 {$worst['label']} 转到 {$best['label']}。";
            }
        }
        foreach ($platforms as $p) {
            if ($p['spend'] === 0 && $p['organic_orders'] >= 3) {
                $out[] = "{$p['label']} 的自然流量已经带来 {$p['organic_orders']} 单：可以试着在 {$p['label']} 上投放广告。";
            }
        }
        if ($t['roas'] !== null && $t['roas'] >= 3 && ! $out) {
            $out[] = "整体 ROAS {$t['roas']}，广告效果不错：可以逐步加预算，观察 ROAS 是否保持。";
        }

        return array_slice($out, 0, 6);
    }

    private static function money(int $minor): string
    {
        return number_format($minor / 100, 2);
    }
}
