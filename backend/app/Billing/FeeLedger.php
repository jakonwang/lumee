<?php

namespace App\Billing;

use App\Currency\ExchangeRates;
use App\Models\Order;
use App\Models\PlatformFee;
use App\Models\Store;

/**
 * Transaction fees: a percentage of each paid order's net amount, at the plan's rate
 * when the order was paid. Refunds add a matching negative credit. Fees accrue in the
 * store's currency and are converted to USD when billed (monthly).
 */
class FeeLedger
{
    public function __construct(private Billing $billing) {}

    public function recordPaid(Order $order): ?PlatformFee
    {
        if ($order->payment_gateway === 'test') {
            return null;   // dev test payments are never charged
        }
        if ($order->stripe_account) {
            return $this->recordCollected($order);
        }
        $store = Store::find($order->store_id);
        $rate = $store ? $this->billing->limits($store)['fee_bp'] : 0;
        if ($rate <= 0 || PlatformFee::withoutGlobalScope('store')->where('order_id', $order->id)->where('type', 'fee')->exists()) {
            return null;   // trial (no fee) or already recorded
        }

        return PlatformFee::withoutGlobalScope('store')->create([
            'store_id' => $order->store_id,
            'order_id' => $order->id,
            'type' => 'fee',
            'rate_bp' => $rate,
            'base_amount' => $order->total,
            'amount' => (int) round($order->total * $rate / 10000),
            'currency' => $order->currency,
        ]);
    }

    /** Stripe Connect: the fee was already taken as an application fee — record it, never bill it. */
    private function recordCollected(Order $order): ?PlatformFee
    {
        $fee = (int) $order->application_fee;
        if ($fee <= 0 || $order->total <= 0 || PlatformFee::withoutGlobalScope('store')->where('order_id', $order->id)->where('type', 'fee')->exists()) {
            return null;
        }

        return PlatformFee::withoutGlobalScope('store')->create([
            'store_id' => $order->store_id,
            'order_id' => $order->id,
            'type' => 'fee',
            'rate_bp' => (int) round($fee * 10000 / $order->total),
            'base_amount' => $order->total,
            'amount' => $fee,
            'currency' => $order->currency,
            'collected_at' => now(),
        ]);
    }

    /** Refunded money isn't charged: credit back the fee on the refunded amount (same rate as the fee). */
    public function recordRefund(Order $order, int $refunded): ?PlatformFee
    {
        $fee = PlatformFee::withoutGlobalScope('store')->where('order_id', $order->id)->where('type', 'fee')->first();
        if (! $fee || $refunded <= 0) {
            return null;
        }

        return PlatformFee::withoutGlobalScope('store')->create([
            'store_id' => $order->store_id,
            'order_id' => $order->id,
            'type' => 'credit',
            'rate_bp' => $fee->rate_bp,
            'base_amount' => -$refunded,
            'amount' => -(int) round($refunded * $fee->rate_bp / 10000),
            'currency' => $order->currency,
            'collected_at' => $fee->collected_at ? now() : null,   // Stripe returned it with refund_application_fee
        ]);
    }

    /**
     * Unbilled fees of a store, converted to USD cents.
     * @return array{usd: ?int, by_currency: array<string,int>, ids: int[]}  usd null = a rate is missing
     */
    public function pending(Store $store): array
    {
        $rows = PlatformFee::withoutGlobalScope('store')->where('store_id', $store->id)->whereNull('invoice_id')->whereNull('collected_at')->get();
        $by = $rows->groupBy('currency')->map(fn ($g) => (int) $g->sum('amount'))->all();
        $usd = 0;
        foreach ($by as $currency => $amount) {
            $rate = app(ExchangeRates::class)->rate($currency, 'USD');
            if ($rate === null) {
                $usd = null;
                break;
            }
            $usd += (int) round($amount * $rate);
        }

        return ['usd' => $usd, 'by_currency' => $by, 'ids' => $rows->pluck('id')->all()];
    }
}
