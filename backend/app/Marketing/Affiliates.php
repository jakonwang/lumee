<?php

namespace App\Marketing;

use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Affiliate programme. A visit with ?ref=CODE sets a cookie (last click wins, 30 days by default);
 * orders placed while it's set are credited to that affiliate. When the order is paid the affiliate
 * earns a commission on the merchandise total (after discounts, before shipping and tax). Refunds
 * shrink it, cancellation voids it. The merchant pays affiliates outside Lumée and marks commissions
 * paid; commissions can only be paid once the return window has passed.
 */
class Affiliates
{
    public const COOKIE = 'lumee_ref';

    public const DEFAULTS = ['cookie_days' => 30, 'return_days' => 30, 'default_rate' => 10];

    public static function settings(Store $store): array
    {
        $s = array_merge(self::DEFAULTS, array_intersect_key($store->settings['affiliates'] ?? [], self::DEFAULTS));

        return ['cookie_days' => (int) $s['cookie_days'], 'return_days' => (int) $s['return_days'], 'default_rate' => (float) $s['default_rate']];
    }

    /** Storefront: remember the affiliate behind ?ref= and count the click (once per browser per day). */
    public function track(Request $request, Response $response, Store $store): void
    {
        $code = trim((string) $request->query('ref'));
        if ($code === '' || mb_strlen($code) > 40) {
            return;
        }
        $a = Affiliate::withoutGlobalScope('store')->where('store_id', $store->id)->where('code', mb_strtolower($code))->where('status', 'active')->first();
        if (! $a) {
            return;
        }
        if ($request->cookie(self::COOKIE) !== (string) $a->id) {
            $a->increment('clicks');
        }
        $response->headers->setCookie(Cookie::make(self::COOKIE, (string) $a->id, self::settings($store)['cookie_days'] * 1440, '/', null, null, true, false, 'Lax'));
    }

    /** Checkout: credit the order to the affiliate in the cookie (not to themselves). */
    public function attribute(Order $order, ?Request $request): void
    {
        $id = (int) $request?->cookie(self::COOKIE);
        if (! $id) {
            return;
        }
        $a = Affiliate::withoutGlobalScope('store')->where('store_id', $order->store_id)->where('status', 'active')->find($id);
        if ($a && mb_strtolower($a->email) !== mb_strtolower((string) $order->email)) {
            $order->forceFill(['affiliate_id' => $a->id])->saveQuietly();
            $order->log('affiliate', __('来自推广员 :p0（:p1）', ['p0' => $a->name, 'p1' => $a->code]));
        }
    }

    private static function base(Order $order): int
    {
        return max(0, (int) $order->subtotal - (int) $order->discount_total - (int) $order->refunded_total);
    }

    /** Paid → commission (pending). */
    public function orderPaid(Order $order): void
    {
        if (! $order->affiliate_id || AffiliateCommission::withoutGlobalScope('store')->where('order_id', $order->id)->exists()) {
            return;
        }
        $a = Affiliate::withoutGlobalScope('store')->find($order->affiliate_id);
        if (! $a) {
            return;
        }
        $base = self::base($order);
        AffiliateCommission::withoutGlobalScope('store')->create([
            'store_id' => $order->store_id, 'affiliate_id' => $a->id, 'order_id' => $order->id, 'base_amount' => $base,
            'amount' => (int) round($base * $a->commission_rate / 100), 'currency' => $order->currency, 'rate' => $a->commission_rate, 'status' => 'pending',
        ]);
    }

    /** Refund / cancel: shrink or void an unpaid commission. */
    public function orderChanged(Order $order): void
    {
        $c = AffiliateCommission::withoutGlobalScope('store')->where('order_id', $order->id)->where('status', 'pending')->first();
        if (! $c) {
            return;
        }
        $base = $order->isCancelled() ? 0 : self::base($order);
        $c->forceFill($base <= 0
            ? ['status' => 'void', 'amount' => 0, 'base_amount' => 0]
            : ['base_amount' => $base, 'amount' => (int) round($base * $c->rate / 100)])->save();
    }

    /** Pending commissions old enough to pay (past the return window). */
    public static function payableQuery(Store $store, int $affiliateId)
    {
        return AffiliateCommission::withoutGlobalScope('store')->where('store_id', $store->id)->where('affiliate_id', $affiliateId)
            ->where('status', 'pending')->where('amount', '>', 0)->where('created_at', '<=', now()->subDays(self::settings($store)['return_days']));
    }

    /** @return array{clicks:int, orders:int, sales:int, pending:int, payable:int, paid:int} (minor units) */
    public static function stats(Store $store, Affiliate $a): array
    {
        $rows = AffiliateCommission::withoutGlobalScope('store')->where('affiliate_id', $a->id)->get();

        return [
            'clicks' => $a->clicks,
            'orders' => $rows->where('status', '!=', 'void')->count(),
            'sales' => (int) $rows->where('status', '!=', 'void')->sum('base_amount'),
            'pending' => (int) $rows->where('status', 'pending')->sum('amount'),
            'payable' => (int) self::payableQuery($store, $a->id)->sum('amount'),
            'paid' => (int) $rows->where('status', 'paid')->sum('amount'),
        ];
    }
}
