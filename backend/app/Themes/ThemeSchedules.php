<?php

namespace App\Themes;

use App\Markets\ShoppingCalendar;
use App\Models\Market;
use App\Models\Store;
use App\Models\StoreTheme;
use App\Models\ThemeSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Campaign versions of the store design on a timetable:
 *  - made from a shopping-calendar event (the automatic designer builds the campaign version) or from
 *    the current draft, for the main site or one market
 *  - review → scheduled (merchant approves) → active (published at starts_at, the live design saved)
 *    → done (the saved design restored at ends_at); cancelled at any time (an active one is taken down)
 *  - with "auto campaigns" on, versions for events entering their preparation window are made by
 *    themselves and wait for review (dashboard to-do)
 */
class ThemeSchedules
{
    public function __construct(private AutoDesigner $designer, private MarketThemes $markets, private ThemeService $themes) {}

    public static function autoEnabled(Store $store): bool
    {
        return (bool) ($store->settings['design']['auto_campaigns'] ?? false);
    }

    /** A campaign version for a calendar event, made by the automatic designer (nothing else changes). */
    public function fromEvent(Store $store, StoreTheme $row, ?Market $market, string $eventKey, string $status = 'scheduled', ?int $userId = null): ThemeSchedule
    {
        $event = collect(ShoppingCalendar::upcoming($market ? (array) $market->countries : []))->firstWhere('key', $eventKey);
        if (! $event) {
            throw ValidationException::withMessages(['event_key' => __('这个活动不在接下来的活动日历里')]);
        }
        $existing = ThemeSchedule::where('store_theme_id', $row->id)->where('market_id', $market?->id)->where('event_key', $eventKey)
            ->whereNotIn('status', ['cancelled'])->first();
        if ($existing) {
            return $existing;
        }
        $result = $this->designer->generate($store, $row, $market, $eventKey, save: false);
        $config = $market ? $this->markets->diff($this->markets->base($row, false), $result['config'], true) : $result['config'];
        $tz = $store->timezone ?: 'UTC';

        return $this->create($row, $market, __($event['name']), $eventKey,
            CarbonImmutable::parse($event['start'], $tz)->startOfDay(), CarbonImmutable::parse($event['end'], $tz)->endOfDay(), $config, $status, $userId, $status === 'review' ? 'auto' : 'manual');
    }

    /** The current draft (main site or market) as a campaign version between two times. */
    public function fromDraft(StoreTheme $row, ?Market $market, string $name, CarbonImmutable $start, CarbonImmutable $end, ?int $userId = null): ThemeSchedule
    {
        if ($end->lte($start) || $end->isPast()) {
            throw ValidationException::withMessages(['ends_at' => __('结束时间要晚于开始时间，并且在未来')]);
        }
        $config = $market ? ($this->markets->row($row, $market)?->draft ?? MarketThemes::empty()) : $this->markets->base($row, true);

        return $this->create($row, $market, $name, null, $start, $end, $config, 'scheduled', $userId, 'manual');
    }

    private function create(StoreTheme $row, ?Market $market, string $name, ?string $event, CarbonImmutable $start, CarbonImmutable $end, array $config, string $status, ?int $userId, string $source): ThemeSchedule
    {
        $clash = ThemeSchedule::where('store_theme_id', $row->id)->where('market_id', $market?->id)->whereIn('status', ['review', 'scheduled', 'active'])
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists();
        if ($clash) {
            throw ValidationException::withMessages(['starts_at' => __('这段时间已经有另一个活动版本了')]);
        }
        $s = new ThemeSchedule(['store_theme_id' => $row->id, 'market_id' => $market?->id, 'name' => mb_substr($name, 0, 120), 'event_key' => $event,
            'starts_at' => $start, 'ends_at' => $end, 'config' => $config, 'status' => $status, 'source' => $source, 'created_by' => $userId]);
        $s->forceFill(['store_id' => $row->store_id])->save();

        return $s;
    }

    public function approve(ThemeSchedule $s): ThemeSchedule
    {
        if ($s->status === 'review') {
            $s->forceFill(['status' => 'scheduled'])->save();
        }

        return $s;
    }

    /** Cancel; a campaign that is live is taken down now (the saved design comes back). */
    public function cancel(ThemeSchedule $s): ThemeSchedule
    {
        $open = in_array($s->status, ['review', 'scheduled', 'active'], true);
        if ($s->status === 'active') {
            $this->finish($s);   // puts the saved design back
        }
        if ($open) {
            $s->forceFill(['status' => 'cancelled'])->save();
        }

        return $s;
    }

    /** Start and end what's due (every few minutes). @return array{started: int, ended: int} */
    public function runDue(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $started = $ended = 0;
        foreach (ThemeSchedule::withoutGlobalScope('store')->where('status', 'active')->where('ends_at', '<=', $now)->get() as $s) {
            $this->finish($s);
            $ended++;
        }
        foreach (ThemeSchedule::withoutGlobalScope('store')->where('status', 'scheduled')->where('starts_at', '<=', $now)->get() as $s) {
            if ($s->ends_at->lte($now)) {
                $s->forceFill(['status' => 'done'])->save();   // missed entirely (e.g. scheduler was down)

                continue;
            }
            $this->start($s);
            $started++;
        }
        // Versions nobody approved before their event ended
        ThemeSchedule::withoutGlobalScope('store')->where('status', 'review')->where('ends_at', '<=', $now)->update(['status' => 'cancelled']);

        return compact('started', 'ended');
    }

    /** Make campaign versions for events entering their preparation window (stores with auto campaigns on). */
    public function prepare(Store $store): int
    {
        if (! self::autoEnabled($store)) {
            return 0;
        }
        $row = $this->themes->ensureLive($store);
        $made = 0;
        foreach ([null, ...Market::where('enabled', true)->get()->all()] as $market) {
            foreach (ShoppingCalendar::upcoming($market ? (array) $market->countries : []) as $e) {
                if (! $e['preparing'] || $e['days_left'] < 1) {
                    continue;
                }
                $exists = ThemeSchedule::where('store_theme_id', $row->id)->where('market_id', $market?->id)->where('event_key', $e['key'])->exists();
                if (! $exists) {
                    try {
                        $this->fromEvent($store, $row, $market, $e['key'], 'review');
                        $made++;
                    } catch (ValidationException) {
                        // overlaps another campaign: leave it to the merchant
                    }
                }
            }
        }

        return $made;
    }

    private function start(ThemeSchedule $s): void
    {
        $row = StoreTheme::withoutGlobalScope('store')->findOrFail($s->store_theme_id);
        $market = $s->market_id ? Market::withoutGlobalScope('store')->find($s->market_id) : null;
        // A design A/B test on the same site would show half the visitors the old design: stop it
        $tests = app(DesignExperiments::class);
        if ($e = $tests->running($row->id, $market?->id)) {
            $tests->finish($e, null, null, mb_substr(__('活动「:p0」上线，测试已停止', ['p0' => $s->name]), 0, 200));
        }
        if ($market) {
            $m = $this->markets->rowOrNew($row, $market);
            $s->previous = $m->published ?? MarketThemes::empty();
            $m->published = $s->config;
            $m->published_at = now();
            $m->save();
        } else {
            $s->previous = $row->published ?? $this->themes->defaults($row->theme);
            $row->published = $s->config;
            $row->published_at = now();
            $row->save();
        }
        $this->markets->snapshot($row, $market, $s->config, 'schedule', null, mb_substr(__('活动开始：:p0', ['p0' => $s->name]), 0, 200));
        $s->forceFill(['status' => 'active'])->save();
    }

    private function finish(ThemeSchedule $s): void
    {
        $row = StoreTheme::withoutGlobalScope('store')->findOrFail($s->store_theme_id);
        $market = $s->market_id ? Market::withoutGlobalScope('store')->find($s->market_id) : null;
        // Restore only if the campaign design is still what's live (a merchant's later publish wins)
        $live = $market ? $this->markets->row($row, $market)?->published : $row->published;
        if ($s->previous !== null && $live == $s->config) {
            if ($market) {
                $m = $this->markets->rowOrNew($row, $market);
                $m->published = $s->previous;
                $m->published_at = now();
                $m->save();
            } else {
                $row->published = $s->previous;
                $row->published_at = now();
                $row->save();
            }
            $this->markets->snapshot($row, $market, $s->previous, 'schedule', null, mb_substr(__('活动结束，恢复原来的样子：:p0', ['p0' => $s->name]), 0, 200));
        }
        $s->forceFill(['status' => 'done'])->save();
    }
}
