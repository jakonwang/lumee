<?php

namespace App\Themes;

use App\Analytics\Visits;
use App\Markets\MarketContext;
use App\Models\DesignExperiment;
use App\Models\Market;
use App\Models\Store;
use App\Models\StoreTheme;
use App\Models\ThemeSchedule;
use App\Testing\Experiments;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A/B tests of the store design (request-scoped). A = what's live, B = a draft frozen when the test
 * started, for the main site or one market. Each visitor always sees the same version (hash of the
 * visitor cookie and the test); bots, previews and untracked requests see A and aren't counted.
 * Sessions started during the test remember their arm, so results come from real sessions and orders:
 * conversion (orders / sessions) decides, add-to-cart rate and revenue per session help read it.
 * Adopting B publishes it; a campaign schedule starting on the same site stops the test.
 */
class DesignExperiments
{
    public const MIN_SESSIONS = 200;   // per arm, before a winner is called

    /** @var array<string, ?DesignExperiment> */
    private array $running = [];

    public function __construct(private ThemeService $themes, private MarketThemes $markets) {}

    public function running(int $rowId, ?int $marketId): ?DesignExperiment
    {
        $key = $rowId.'|'.$marketId;
        if (! array_key_exists($key, $this->running)) {
            $this->running[$key] = DesignExperiment::withoutGlobalScope('store')->where('store_theme_id', $rowId)->where('market_id', $marketId)
                ->where('status', 'running')->latest('id')->first();
        }

        return $this->running[$key];
    }

    public static function arm(DesignExperiment $e, ?string $visitor): ?string
    {
        return $visitor ? (crc32($visitor.'|design'.$e->id) % 2 === 0 ? 'a' : 'b') : null;
    }

    /**
     * The B design to render for this visitor, or null for A. Main site: a full config; market: its layer.
     */
    public function variantFor(StoreTheme $row, ?Market $market): ?array
    {
        $e = $this->running($row->id, $market?->id);
        if (! $e || ! app(Visits::class)->trackable(request())) {
            return null;
        }

        return self::arm($e, app(Visits::class)->visitorId()) === 'b' ? $e->variant : null;
    }

    /** For a new analytics session: the running test of the live design on this site and the visitor's arm. */
    public function assignment(int $storeId, string $visitor): array
    {
        $row = $this->themes->liveRow(Store::find($storeId));
        $market = app(MarketContext::class)->market();
        $e = $row ? $this->running($row->id, $market?->id) : null;

        return $e ? ['design_experiment_id' => $e->id, 'design_arm' => self::arm($e, $visitor)] : [];
    }

    /** Test the current draft (B) against what's live (A). */
    public function start(StoreTheme $row, ?Market $market, string $name, ?int $userId = null): DesignExperiment
    {
        if ($this->running($row->id, $market?->id)) {
            throw ValidationException::withMessages(['experiment' => __('这个站点已经有一个正在进行的 A/B 测试')]);
        }
        if (ThemeSchedule::where('store_theme_id', $row->id)->where('market_id', $market?->id)->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['experiment' => __('活动版本上线期间不能做 A/B 测试')]);
        }
        if ($market) {
            $m = $this->markets->row($row, $market);
            $variant = $m?->draft ?? MarketThemes::empty();
            $live = $m?->published ?? MarketThemes::empty();
        } else {
            $variant = $this->markets->base($row, true);
            $live = $this->markets->base($row, false);
        }
        if ($variant == $live) {
            throw ValidationException::withMessages(['experiment' => __('草稿和线上一样，先在草稿里做出要测试的修改')]);
        }
        $e = new DesignExperiment(['store_theme_id' => $row->id, 'market_id' => $market?->id, 'name' => mb_substr($name, 0, 120),
            'variant' => $variant, 'status' => 'running', 'created_by' => $userId, 'started_at' => now()]);
        $e->forceFill(['store_id' => $row->store_id])->save();
        $this->running = [];

        return $e;
    }

    /**
     * Results per arm from the sessions that saw it.
     *
     * @return array{a: array, b: array, p_value: ?float, p_cart: ?float, uplift: ?float, leader: ?string, ready: bool, min: int}
     */
    public static function stats(DesignExperiment $e): array
    {
        $rows = DB::table('analytics_sessions as s')->leftJoin('orders as o', 'o.id', '=', 's.order_id')
            ->where('s.design_experiment_id', $e->id)->groupBy('s.design_arm')
            ->selectRaw('s.design_arm as arm, COUNT(*) as sessions, SUM(CASE WHEN s.added_to_cart THEN 1 ELSE 0 END) as carts, '
                .'SUM(CASE WHEN s.order_id IS NOT NULL THEN 1 ELSE 0 END) as orders, '
                .'SUM(CASE WHEN o.id IS NOT NULL AND o.cancelled_at IS NULL THEN o.total / COALESCE(NULLIF(o.exchange_rate, 0), 1) ELSE 0 END) as revenue')
            ->get()->keyBy('arm');
        $arm = function (string $k) use ($rows) {
            $r = $rows->get($k);
            $n = (int) ($r->sessions ?? 0);

            return ['sessions' => $n, 'carts' => (int) ($r->carts ?? 0), 'orders' => (int) ($r->orders ?? 0), 'revenue' => round((float) ($r->revenue ?? 0), 2),
                'cart_rate' => $n ? round(($r->carts / $n) * 100, 2) : 0.0, 'conversion' => $n ? round(($r->orders / $n) * 100, 2) : 0.0,
                'revenue_per_session' => $n ? round(($r->revenue ?? 0) / $n, 2) : 0.0];
        };
        $a = $arm('a');
        $b = $arm('b');
        $p = Experiments::zTest($a['sessions'], $a['orders'], $b['sessions'], $b['orders']);
        $pc = Experiments::zTest($a['sessions'], $a['carts'], $b['sessions'], $b['carts']);
        $ready = $a['sessions'] >= self::MIN_SESSIONS && $b['sessions'] >= self::MIN_SESSIONS && $p !== null && $p < 0.05;

        return [
            'a' => $a, 'b' => $b, 'p_value' => $p === null ? null : round($p, 4), 'p_cart' => $pc === null ? null : round($pc, 4),
            'uplift' => $a['conversion'] > 0 ? round(($b['conversion'] - $a['conversion']) / $a['conversion'] * 100, 1) : null,
            'leader' => $a['conversion'] === $b['conversion'] ? null : ($b['conversion'] > $a['conversion'] ? 'b' : 'a'),
            'ready' => $ready, 'min' => self::MIN_SESSIONS,
        ];
    }

    /** End the test. Winner B: its design is published (and becomes the draft); A or none: nothing changes. */
    public function finish(DesignExperiment $e, ?string $winner, ?int $userId = null, ?string $note = null): DesignExperiment
    {
        if ($e->status !== 'running') {
            return $e;
        }
        $row = StoreTheme::withoutGlobalScope('store')->findOrFail($e->store_theme_id);
        $market = $e->market_id ? Market::withoutGlobalScope('store')->find($e->market_id) : null;
        if ($winner === 'b') {
            if ($market) {
                $m = $this->markets->rowOrNew($row, $market);
                $m->published = $e->variant;
                $m->draft = $e->variant;
                $m->published_at = now();
                $m->save();
            } else {
                $row->published = $e->variant;
                $row->draft = $e->variant;
                $row->published_at = now();
                $row->save();
            }
            $this->markets->snapshot($row, $market, $e->variant, 'experiment', $userId, mb_substr(__('A/B 测试采用版本 B：:p0', ['p0' => $e->name]), 0, 200));
        }
        $e->forceFill(['status' => $winner ? 'finished' : 'stopped', 'winner' => $winner, 'ended_at' => now(), 'note' => $note])->save();
        $this->running = [];

        return $e;
    }
}
