<?php

namespace App\Billing;

use App\Models\PlatformInvoice;
use App\Models\Store;
use App\Models\Subscription;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A store's billing state and plan limits.
 *
 *   trialing  — within the free trial, no subscription yet
 *   active    — paid (or ops-granted) subscription in good standing
 *   past_due  — renewal payment failed, still within the grace period
 *   expired   — trial over / subscription ended / grace period over → store paused
 *   suspended — paused by platform ops
 *
 * Only trialing / active / past_due stores are "open" (storefront sells, admin works).
 */
class Billing
{
    public const OPEN = ['trialing', 'active', 'past_due'];

    public function plans(): array
    {
        return \App\Support\Tr::deep(config('plans.plans'));   // names/taglines/features in the admin's language
    }

    public function plan(string $key): ?array
    {
        return $this->plans()[$key] ?? null;
    }

    public function subscription(Store $store): ?Subscription
    {
        return Subscription::withoutGlobalScope('store')->where('store_id', $store->id)->first();
    }

    public function state(Store $store, ?Subscription $sub = null): string
    {
        if ($store->status === 'suspended') {
            return 'suspended';
        }
        $sub ??= $this->subscription($store);

        if ($sub && $sub->status !== 'canceled') {
            // Test / ops-granted subscriptions end at their period end; Stripe tells us itself (webhooks)
            $lapsed = $sub->provider !== 'stripe' && $sub->current_period_end && $sub->current_period_end->isPast();
            if (! $lapsed && $sub->status === 'active') {
                return 'active';
            }
            if (! $lapsed && $sub->status === 'past_due') {
                $since = $sub->past_due_since ?? $sub->updated_at;

                return $since->copy()->addDays((int) config('plans.grace_days'))->isFuture() ? 'past_due' : 'expired';
            }
        }

        return $store->trial_ends_at?->isFuture() ? 'trialing' : 'expired';
    }

    public function isOpen(Store $store): bool
    {
        return in_array($this->state($store), self::OPEN, true);
    }

    /** What the store may use right now. Closed stores get the trial limits (for display only). */
    public function limits(Store $store): array
    {
        $state = $this->state($store);
        $sub = in_array($state, ['active', 'past_due'], true) ? $this->subscription($store) : null;
        $plan = $sub ? $this->plan($sub->plan) : null;
        $p = $plan ?? config('plans.trial');

        return [
            'plan' => $sub?->plan ?? ($state === 'trialing' ? 'trial' : null),
            'staff' => (int) $p['staff'],
            'custom_domains' => (bool) $p['custom_domains'],
            'marketing_emails' => (int) ($p['marketing_emails'] ?? 0),
            'fee_bp' => $plan ? (int) $plan['fee_bp'] : 0,
        ];
    }

    /** Compact status for the admin shell (banners, store switcher). */
    public function brief(Store $store): array
    {
        $sub = $this->subscription($store);
        $state = $this->state($store, $sub);

        return [
            'state' => $state,
            'plan' => in_array($state, ['active', 'past_due'], true) ? $sub?->plan : null,
            'plan_name' => in_array($state, ['active', 'past_due'], true) ? ($this->plan((string) $sub?->plan)['name'] ?? null) : null,
            'trial_ends_at' => $store->trial_ends_at,
            'trial_days_left' => $state === 'trialing' ? max(0, (int) ceil(now()->diffInHours($store->trial_ends_at) / 24)) : null,
            'cancel_at_period_end' => (bool) $sub?->cancel_at_period_end,
            'current_period_end' => $sub?->current_period_end,
        ];
    }

    public function monthlyPrice(string $plan, string $interval): int
    {
        $p = $this->plan($plan);

        return $interval === 'year' ? (int) round($p['year'] / 12) : (int) $p['month'];
    }

    /** "stripe" (real), "test" (dev, instant) or "unavailable" (no payment provider configured). */
    public function mode(): string
    {
        if (config('plans.test_mode')) {
            return 'test';
        }

        return filled(config('services.stripe.secret')) ? 'stripe' : 'unavailable';
    }

    // ── Local subscription bookkeeping (test mode + ops grants + Stripe sync) ──

    /** Activate (or switch to) a plan without a payment provider — dev test mode. Records a paid invoice. */
    public function activateTest(Store $store, string $plan, string $interval): Subscription
    {
        return DB::transaction(function () use ($store, $plan, $interval) {
            $sub = $this->upsert($store, [
                'plan' => $plan, 'interval' => $interval, 'status' => 'active', 'provider' => 'test',
                'current_period_end' => $this->periodEnd(now(), $interval), 'cancel_at_period_end' => false,
                'past_due_since' => null, 'ended_at' => null, 'stripe_subscription_id' => null,
            ]);
            $this->recordSubscriptionInvoice($store, $sub, now(), $sub->current_period_end, null);

            return $sub;
        });
    }

    /** Ops: give a store a plan for free until $until (null = no end). */
    public function grant(Store $store, string $plan, ?CarbonInterface $until, ?string $note): Subscription
    {
        $existing = $this->subscription($store);
        if ($existing?->provider === 'stripe' && $existing->status !== 'canceled') {
            throw ValidationException::withMessages(['plan' => __('该店铺有正在扣费的 Stripe 订阅，请先让商家取消')]);
        }

        return $this->upsert($store, [
            'plan' => $plan, 'interval' => 'month', 'status' => 'active', 'provider' => 'manual',
            'current_period_end' => $until, 'cancel_at_period_end' => false, 'past_due_since' => null,
            'ended_at' => null, 'stripe_subscription_id' => null, 'note' => $note,
        ]);
    }

    public function upsert(Store $store, array $attrs): Subscription
    {
        $sub = Subscription::withoutGlobalScope('store')->updateOrCreate(['store_id' => $store->id], $attrs);
        $store->forceFill(['plan' => $sub->status === 'canceled' ? 'none' : $sub->plan])->save();

        return $sub;
    }

    public function recordSubscriptionInvoice(Store $store, Subscription $sub, $start, $end, ?string $ref, ?int $amount = null): PlatformInvoice
    {
        $plan = $this->plan($sub->plan);

        return PlatformInvoice::withoutGlobalScope('store')->create([
            'store_id' => $store->id,
            'number' => PlatformInvoice::nextNumber(),
            'kind' => 'subscription',
            'description' => $plan['name'].' · '.($sub->interval === 'year' ? __('年付') : __('月付')),
            'amount' => $amount ?? (int) $plan[$sub->interval],
            'status' => 'paid',
            'period_start' => $start,
            'period_end' => $end,
            'provider_ref' => $ref,
            'paid_at' => now(),
        ]);
    }

    public function periodEnd(CarbonInterface $from, string $interval): CarbonInterface
    {
        return $interval === 'year' ? $from->copy()->addYearNoOverflow() : $from->copy()->addMonthNoOverflow();
    }
}
