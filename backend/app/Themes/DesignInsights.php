<?php

namespace App\Themes;

use App\LeadFinder\LeadAi;
use App\Models\DesignInsight;
use App\Models\Market;
use App\Models\Store;
use App\Models\ThemeVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * How each market's store design performs (from analytics sessions and orders), and a weekly look
 * with findings (rules on the numbers) and design suggestions (Claude, when the store has a key and
 * AI allowance left). Main site = sessions without a market.
 */
class DesignInsights
{
    /** Minimum sessions in a week before a market is looked at. */
    public const MIN_WEEKLY_SESSIONS = 50;

    public function __construct(private ThemeService $themes, private MarketThemes $markets) {}

    /**
     * Funnel numbers for one site over [$from, $to).
     *
     * @return array{sessions: int, mobile_share: float, bounce: float, home_bounce: ?float, product_view: float, cart: float, checkout: float, conversion: float, revenue_per_session: float, orders: int, revenue: float, mobile: array, desktop: array}
     */
    public function metrics(Store $store, ?Market $market, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $q = fn () => DB::table('analytics_sessions as s')->where('s.store_id', $store->id)
            ->when($market, fn ($w) => $w->where('s.market_id', $market->id), fn ($w) => $w->whereNull('s.market_id'))
            ->where('s.started_at', '>=', $from)->where('s.started_at', '<', $to);
        $agg = fn ($query) => $query->leftJoin('orders as o', 'o.id', '=', 's.order_id')->selectRaw(
            'COUNT(*) as n, SUM(CASE WHEN s.pageviews <= 1 AND NOT s.viewed_product THEN 1 ELSE 0 END) as bounced, '
            .'SUM(CASE WHEN s.viewed_product THEN 1 ELSE 0 END) as viewed, SUM(CASE WHEN s.added_to_cart THEN 1 ELSE 0 END) as carts, '
            .'SUM(CASE WHEN s.reached_checkout THEN 1 ELSE 0 END) as checkouts, SUM(CASE WHEN s.order_id IS NOT NULL THEN 1 ELSE 0 END) as orders, '
            .'SUM(CASE WHEN o.id IS NOT NULL AND o.cancelled_at IS NULL THEN o.total / COALESCE(NULLIF(o.exchange_rate, 0), 1) ELSE 0 END) as revenue')->first();
        $pct = fn ($x, $n) => $n ? round($x / $n * 100, 1) : 0.0;
        $shape = function ($r) use ($pct) {
            $n = (int) ($r->n ?? 0);

            return ['sessions' => $n, 'bounce' => $pct($r->bounced ?? 0, $n), 'product_view' => $pct($r->viewed ?? 0, $n), 'cart' => $pct($r->carts ?? 0, $n),
                'checkout' => $pct($r->checkouts ?? 0, $n), 'conversion' => $pct($r->orders ?? 0, $n), 'orders' => (int) ($r->orders ?? 0),
                'revenue' => round((float) ($r->revenue ?? 0), 2), 'revenue_per_session' => $n ? round(($r->revenue ?? 0) / $n, 2) : 0.0];
        };
        $all = $shape($agg($q()));
        $mobile = $shape($agg($q()->where('s.device', 'mobile')));
        $desktop = $shape($agg($q()->where('s.device', '!=', 'mobile')));
        $prefix = $market ? $market->prefix() : '';
        $home = $q()->whereIn('s.landing_path', array_unique(['/', $prefix ?: '/', $prefix.'/']))
            ->selectRaw('COUNT(*) as n, SUM(CASE WHEN s.pageviews <= 1 AND NOT s.viewed_product THEN 1 ELSE 0 END) as bounced')->first();

        return [...$all, 'mobile_share' => $pct($mobile['sessions'], $all['sessions']),
            'home_bounce' => ($home->n ?? 0) >= 20 ? $pct($home->bounced, $home->n) : null, 'mobile' => $mobile, 'desktop' => $desktop];
    }

    /** Every site's numbers for the last $days, with the period before for comparison, and when its design changed. */
    public function overview(Store $store, int $days): array
    {
        $to = CarbonImmutable::now();
        $from = $to->subDays($days);
        $row = $this->themes->ensureLive($store);
        $sites = [null, ...Market::orderBy('position')->orderBy('id')->get()->all()];

        return array_map(fn (?Market $m) => [
            'market' => $m ? ['id' => $m->id, 'name' => $m->name, 'currency' => $m->currency] : null,
            'now' => $this->metrics($store, $m, $from, $to),
            'before' => $this->metrics($store, $m, $from->subDays($days), $from),
            'changes' => ThemeVersion::where('store_theme_id', $row->id)->where('market_id', $m?->id)->where('created_at', '>=', $from)
                ->whereIn('source', ['publish', 'schedule', 'experiment'])->orderByDesc('id')->limit(10)->get(['id', 'source', 'note', 'created_at']),
        ], $sites);
    }

    /** Findings from the numbers (merchant language). @return array<int, array{level: string, code: string, message: string}> */
    public static function findings(array $now, array $before, ?string $region): array
    {
        $out = [];
        $add = function (string $level, string $code, string $message) use (&$out) {
            $out[] = compact('level', 'code', 'message');
        };
        if ($now['mobile']['sessions'] >= 30 && $now['mobile']['bounce'] >= 60) {
            $add('warning', 'mobile_bounce', __('手机上 :p0% 的访客只看了一页就走了：首屏可能太慢或看不到想要的东西，试试把商品模块往上移、压缩首屏图片', ['p0' => $now['mobile']['bounce']]));
        }
        if ($now['home_bounce'] !== null && $now['home_bounce'] >= 55) {
            $add('warning', 'home_bounce', __('从首页进来的访客 :p0% 直接离开，首页需要更快让人看到商品和优惠', ['p0' => $now['home_bounce']]));
        }
        if ($now['sessions'] >= 100 && $now['cart'] < 3) {
            $add('warning', 'low_cart', __('加购率只有 :p0%，商品图片、价格展示或信任信息（退货、支付方式）可能不够', ['p0' => $now['cart']]));
        }
        if ($now['mobile']['sessions'] >= 50 && $now['desktop']['sessions'] >= 50 && $now['desktop']['conversion'] > 0
            && $now['mobile']['conversion'] < $now['desktop']['conversion'] / 2) {
            $add('warning', 'mobile_conversion', __('手机转化率（:p0%）不到电脑（:p1%）的一半，重点检查手机端的首屏、商品卡片和结账', ['p0' => $now['mobile']['conversion'], 'p1' => $now['desktop']['conversion']]));
        }
        if ($before['sessions'] >= 50 && $before['conversion'] > 0 && $now['conversion'] < $before['conversion'] * 0.7) {
            $add('error', 'conversion_drop', __('转化率从 :p0% 降到 :p1%，看看这段时间装修有没有改动', ['p0' => $before['conversion'], 'p1' => $now['conversion']]));
        }
        if (! $out && $now['sessions'] >= 50) {
            $add('info', 'healthy', __('这段时间的数据没有明显问题'));
        }

        return $out;
    }

    /**
     * Look at last week for every site with enough visits: findings, and Claude's suggestions when possible.
     * Saved per site and week; running it again in the same week updates it. @return int how many sites
     */
    public function weekly(Store $store, bool $useAi = true): int
    {
        $week = CarbonImmutable::now($store->timezone ?: 'UTC')->startOfWeek();
        $from = CarbonImmutable::now()->subDays(7);
        $done = 0;
        foreach ([null, ...Market::where('enabled', true)->get()->all()] as $m) {
            $now = $this->metrics($store, $m, $from, CarbonImmutable::now());
            if ($now['sessions'] < self::MIN_WEEKLY_SESSIONS) {
                continue;
            }
            $before = $this->metrics($store, $m, $from->subDays(7), $from);
            $region = $m ? AutoDesigner::region($m, $store) : null;
            $findings = self::findings($now, $before, $region);
            $suggestions = $useAi ? $this->suggest($store, $m, $now, $before, $findings) : null;
            $i = DesignInsight::firstOrNew(['market_id' => $m?->id, 'week' => $week->toDateString()]);
            $i->fill(['metrics' => ['now' => $now, 'before' => $before], 'findings' => $findings, 'suggestions' => $suggestions ?? $i->suggestions]);
            $i->forceFill(['store_id' => $store->id])->save();
            $done++;
        }

        return $done;
    }

    /** Claude's design suggestions for a site from its numbers (null without a key or allowance). */
    private function suggest(Store $store, ?Market $market, array $now, array $before, array $findings): ?array
    {
        $status = AiDesigner::status($store);
        if (! $status['ready'] || $status['used'] >= $status['quota']) {
            return null;
        }
        $row = $this->themes->ensureLive($store);
        $config = $market ? $this->markets->effective($row, $market, false) : $this->markets->base($row, false);
        $blocks = collect($config['sections'])->where('enabled', true)->map(fn ($s) => $s['type'].(isset($s['settings']['visibility']) && $s['settings']['visibility'] !== 'all' ? "({$s['settings']['visibility']})" : ''))->implode(' → ');
        $lang = str_starts_with((string) ($store->owner?->locale ?: 'zh_CN'), 'zh') ? 'Simplified Chinese' : 'English';
        try {
            $r = app(LeadAi::class)->json($store, implode("\n\n", [
                'You advise an online store on its storefront design. From last week\'s numbers, give 2 to 4 specific changes to the home page or product page that would most likely raise conversion for this site\'s shoppers. Name the blocks to move, add, hide or change. Be concrete and brief.',
                "Write title and detail in $lang; each detail under 50 words.",
                'Site: '.($market ? $market->name.' ('.implode(', ', (array) $market->countries).', '.$market->currency.', region '.AutoDesigner::region($market, $store).')' : 'main site'),
                'Home page blocks in order: '.($blocks ?: 'none'),
                'Last 7 days: '.json_encode($now),
                'The 7 days before: '.json_encode($before),
                'Findings: '.(collect($findings)->pluck('message')->implode(' | ') ?: 'none'),
            ]), ['type' => 'object', 'additionalProperties' => false, 'required' => ['suggestions'], 'properties' => [
                'suggestions' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['title', 'detail'],
                    'properties' => ['title' => ['type' => 'string'], 'detail' => ['type' => 'string']]]],
            ]], 'low', 3000);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
        // Counts against the AI design allowance
        $store->refresh();
        $settings = $store->settings ?? [];
        $settings['design']['ai_usage'] = ['month' => now()->format('Y-m'), 'count' => $status['used'] + 1];
        $store->forceFill(['settings' => $settings])->save();

        return array_slice(array_values(array_filter((array) ($r['suggestions'] ?? []), 'is_array')), 0, 4);
    }
}
