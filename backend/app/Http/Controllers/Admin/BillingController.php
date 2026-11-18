<?php

namespace App\Http\Controllers\Admin;

use App\Billing\Billing;
use App\Billing\FeeLedger;
use App\Billing\StripeBilling;
use App\Http\Controllers\Controller;
use App\Models\PlatformInvoice;
use App\Support\Money;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Plan & billing page. Viewing: any member. Changing the plan: owner only (see routes). */
class BillingController extends Controller
{
    public function show(CurrentStore $current, Billing $billing): JsonResponse
    {
        return response()->json($this->payload($current, $billing));
    }

    public function subscribe(Request $request, CurrentStore $current, Billing $billing): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', Rule::in(array_keys($billing->plans()))],
            'interval' => ['required', Rule::in(['month', 'year'])],
        ]);
        $store = $current->getOrFail();
        if ($store->status === 'suspended') {
            throw ValidationException::withMessages(['plan' => __('店铺已被平台暂停，请联系 Lumée 客服')]);
        }
        $sub = $billing->subscription($store);
        $live = $sub && $sub->status !== 'canceled';

        if ($live && $sub->plan === $data['plan'] && $sub->interval === $data['interval'] && ! $sub->cancel_at_period_end) {
            throw ValidationException::withMessages(['plan' => __('你已经在使用这个套餐')]);
        }
        if ($live && $sub->provider === 'manual') {
            throw ValidationException::withMessages(['plan' => __('你的套餐由 Lumée 平台赠送，如需更改请联系客服')]);
        }

        // Fewer staff seats than people already in the store → ask to remove some first
        $staff = $store->users()->count();
        if ($staff > $billing->plan($data['plan'])['staff']) {
            throw ValidationException::withMessages(['plan' => __('店铺现有 :p0 位成员，超过该套餐的上限，请先移除部分员工', ['p0' => $staff])]);
        }

        try {
            switch ($billing->mode()) {
                case 'test':
                    $billing->activateTest($store, $data['plan'], $data['interval']);
                    break;
                case 'stripe':
                    if ($live && $sub->provider === 'stripe') {
                        app(StripeBilling::class)->change($sub, $data['plan'], $data['interval']);
                        break;
                    }
                    $url = app(StripeBilling::class)->checkout(
                        $store, $data['plan'], $data['interval'], $request->user()->email,
                        rtrim((string) config('lumee.admin_url'), '/').'/settings/billing',
                    );

                    return response()->json(['redirect_url' => $url]);
                default:
                    throw ValidationException::withMessages(['plan' => __('平台暂未开通在线订阅，请联系 Lumée 客服开通套餐')]);
            }
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['plan' => $e->getMessage()]);
        }

        return response()->json($this->payload($current, $billing));
    }

    /** Cancel at the end of the paid period (the store keeps working until then). */
    public function cancel(CurrentStore $current, Billing $billing): JsonResponse
    {
        return $this->setCancel($current, $billing, true);
    }

    public function resume(CurrentStore $current, Billing $billing): JsonResponse
    {
        return $this->setCancel($current, $billing, false);
    }

    private function setCancel(CurrentStore $current, Billing $billing, bool $cancel): JsonResponse
    {
        $store = $current->getOrFail();
        $sub = $billing->subscription($store);
        if (! $sub || $sub->status === 'canceled' || $sub->provider === 'manual') {
            throw ValidationException::withMessages(['plan' => __('没有可以操作的订阅')]);
        }
        try {
            $sub->provider === 'stripe'
                ? app(StripeBilling::class)->setCancelAtPeriodEnd($sub, $cancel)
                : $billing->upsert($store, ['cancel_at_period_end' => $cancel]);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['plan' => $e->getMessage()]);
        }

        return response()->json($this->payload($current, $billing));
    }

    private function payload(CurrentStore $current, Billing $billing): array
    {
        $store = $current->getOrFail();
        $sub = $billing->subscription($store);
        $limits = $billing->limits($store);
        $pending = app(FeeLedger::class)->pending($store);

        return [
            ...$billing->brief($store),
            'mode' => $billing->mode(),
            'is_owner' => $current->isOwner(),
            'suspended_reason' => $store->status === 'suspended' ? $store->suspended_reason : null,
            'subscription' => $sub ? [
                'plan' => $sub->plan,
                'plan_name' => $billing->plan($sub->plan)['name'] ?? $sub->plan,
                'interval' => $sub->interval,
                'status' => $sub->status,
                'provider' => $sub->provider,
                'current_period_end' => $sub->current_period_end,
                'cancel_at_period_end' => $sub->cancel_at_period_end,
                'note' => $sub->provider === 'manual' ? $sub->note : null,
            ] : null,
            'limits' => $limits,
            'usage' => ['staff' => $store->users()->count()],
            'fees' => [
                'rate' => $limits['fee_bp'] / 100,
                'pending_usd' => $pending['usd'] !== null ? Money::format($pending['usd']) : null,
            ],
            'plans' => collect($billing->plans())->map(fn ($p, $key) => [
                'key' => $key,
                'name' => $p['name'],
                'tagline' => $p['tagline'],
                'month' => Money::format($p['month']),
                'year' => Money::format($p['year']),
                'year_monthly' => Money::format((int) round($p['year'] / 12)),
                'fee_rate' => $p['fee_bp'] / 100,
                'staff' => $p['staff'],
                'custom_domains' => $p['custom_domains'],
                'features' => $p['features'],
            ])->values(),
            'invoices' => PlatformInvoice::withoutGlobalScope('store')->where('store_id', $store->id)
                ->latest('id')->limit(24)->get()->map->toApi(),
        ];
    }
}
