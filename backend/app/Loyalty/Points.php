<?php

namespace App\Loyalty;

use App\GiftCards\GiftCards;
use App\Models\Customer;
use App\Models\GiftCard;
use App\Models\Order;
use App\Models\PointTransaction;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Loyalty points: customers earn points on paid orders and turn them into store credit.
 * Redeeming creates a personal gift card (source "points") and puts it in the cart, so spending
 * the credit reuses the gift card checkout (partial use, balance kept, restored on cancel).
 * stores.settings['loyalty'] = {enabled, earn_rate: points per 1 unit of currency, redeem_points: points per 1 unit of credit, min_redeem}
 */
class Points
{
    public const DEFAULTS = ['enabled' => false, 'earn_rate' => 1, 'redeem_points' => 100, 'min_redeem' => 100];

    public static function settings(Store $store): array
    {
        $s = array_replace(self::DEFAULTS, $store->settings['loyalty'] ?? []);

        return ['enabled' => (bool) $s['enabled'], 'earn_rate' => max(0, (float) $s['earn_rate']),
            'redeem_points' => max(1, (int) $s['redeem_points']), 'min_redeem' => max(1, (int) $s['min_redeem'])];
    }

    /** Credit (minor units) that a number of points buys. */
    public static function value(Store $store, int $points): int
    {
        $credit = intdiv($points * 100, self::settings($store)['redeem_points']);   // in the store currency
        $markets = app(\App\Markets\MarketContext::class);

        return $markets->activeFor((int) $store->id) ? (int) $markets->money($credit) : $credit;
    }

    /** Award points for a paid order (once per order). */
    public function earn(Order $order): int
    {
        $store = Store::find($order->store_id);
        $s = self::settings($store);
        if (! $s['enabled'] || ! $order->customer_id || $order->total <= 0) {
            return 0;
        }
        $points = (int) floor($order->inStoreCurrency($order->total) / 100 * $s['earn_rate']);   // earn rate is per store-currency unit
        if ($points <= 0 || PointTransaction::withoutGlobalScope('store')->where('order_id', $order->id)->where('kind', 'earned')->exists()) {
            return 0;
        }
        $this->apply($order->customer_id, $points, 'earned', $order->id);
        $order->log('points', __('买家获得 :p0 积分', ['p0' => $points]));

        return $points;
    }

    /** Take back what an order earned (cancelled), never below zero. */
    public function reverse(Order $order): int
    {
        $earned = (int) PointTransaction::withoutGlobalScope('store')->where('order_id', $order->id)->where('kind', 'earned')->sum('points');
        $reversed = -(int) PointTransaction::withoutGlobalScope('store')->where('order_id', $order->id)->where('kind', 'reversed')->sum('points');
        $left = $earned - $reversed;
        if ($left <= 0 || ! $order->customer_id) {
            return 0;
        }
        $balance = (int) Customer::withoutGlobalScope('store')->whereKey($order->customer_id)->value('points');
        $take = min($left, max(0, $balance));
        if ($take > 0) {
            $this->apply($order->customer_id, -$take, 'reversed', $order->id);
        }

        return $take;
    }

    /** Turn points into store credit (a gift card for this customer). */
    public function redeem(Customer $customer, int $points): GiftCard
    {
        $store = Store::find($customer->store_id);
        $s = self::settings($store);
        if (! $s['enabled']) {
            throw ValidationException::withMessages(['points' => __('Points are not available right now.')]);
        }
        if ($points < $s['min_redeem']) {
            throw ValidationException::withMessages(['points' => __('You need at least :count points to redeem.', ['count' => $s['min_redeem']])]);
        }

        return DB::transaction(function () use ($customer, $points, $store) {
            $locked = Customer::withoutGlobalScope('store')->whereKey($customer->id)->lockForUpdate()->first();
            if ($locked->points < $points) {
                throw ValidationException::withMessages(['points' => __('You don’t have enough points.')]);
            }
            $value = self::value($store, $points);
            if ($value <= 0) {
                throw ValidationException::withMessages(['points' => __('You need at least :count points to redeem.', ['count' => self::settings($store)['min_redeem']])]);
            }
            $gc = app(GiftCards::class);
            $code = $gc->generateCode();
            $card = GiftCard::withoutGlobalScope('store')->forceCreate([
                'store_id' => $store->id, 'code' => $code, 'last4' => substr($code, -4), 'initial_value' => $value, 'balance' => $value,
                'currency' => $store->currency, 'customer_id' => $locked->id, 'source' => 'points', 'recipient_email' => $locked->email,
                'note' => __(':p0 积分兑换', ['p0' => $points]),
            ]);
            $gc->log($card, $value, 'issued');
            $this->apply($locked->id, -$points, 'redeemed', null, null, $card->id);

            return $card;
        });
    }

    public function adjust(Customer $customer, int $delta, ?string $note): void
    {
        DB::transaction(function () use ($customer, $delta, $note) {
            $balance = (int) Customer::withoutGlobalScope('store')->whereKey($customer->id)->lockForUpdate()->value('points');
            $delta = max($delta, -$balance);   // never below zero
            if ($delta !== 0) {
                $this->apply($customer->id, $delta, 'adjusted', null, $note);
            }
        });
    }

    private function apply(int $customerId, int $points, string $kind, ?int $orderId = null, ?string $note = null, ?int $giftCardId = null): void
    {
        Customer::withoutGlobalScope('store')->whereKey($customerId)->increment('points', $points);
        $c = Customer::withoutGlobalScope('store')->find($customerId, ['id', 'store_id', 'points']);
        $user = request()?->user();
        PointTransaction::withoutGlobalScope('store')->forceCreate([
            'store_id' => $c->store_id, 'customer_id' => $c->id, 'points' => $points, 'balance_after' => $c->points, 'kind' => $kind,
            'order_id' => $orderId, 'gift_card_id' => $giftCardId, 'user_id' => $user instanceof User ? $user->id : null,
            'note' => $note !== null ? mb_substr($note, 0, 255) : null, 'created_at' => now(),
        ]);
    }
}
