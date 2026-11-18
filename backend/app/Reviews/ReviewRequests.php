<?php

namespace App\Reviews;

use App\Models\Order;
use App\Models\ProductReview;
use App\Models\Store;
use App\Notifications\NotificationSettings;
use App\Notifications\OrderNotifier;

/**
 * "How is everything?" emails: once per order, N days after it shipped (Reviews settings),
 * only when reviews are on, the email type is switched on, and the buyer hasn't already
 * reviewed everything in the order. Run by lumee:review-requests (hourly).
 */
class ReviewRequests
{
    /** Don't ask about orders that shipped longer ago than this. */
    public const MAX_AGE_DAYS = 60;

    public function run(): int
    {
        $sent = 0;
        $stores = [];
        Order::withoutGlobalScope('store')
            ->where('fulfillment_status', 'fulfilled')
            ->whereNull('review_requested_at')
            ->whereNull('cancelled_at')
            ->whereNotIn('financial_status', ['refunded', 'voided', 'pending'])
            ->where('fulfilled_at', '<=', now()->subDays(min(Reviews::REQUEST_DELAYS)))
            ->where('fulfilled_at', '>=', now()->subDays(self::MAX_AGE_DAYS))
            ->with('items')
            ->orderBy('id')
            ->chunkById(200, function ($orders) use (&$sent, &$stores) {
                foreach ($orders as $order) {
                    $store = $stores[$order->store_id] ??= Store::with('owner')->find($order->store_id);
                    $sent += $store && $this->sendIfDue($order, $store) ? 1 : 0;
                }
            });

        return $sent;
    }

    public function sendIfDue(Order $order, Store $store): bool
    {
        $settings = Reviews::settings($store);
        if (! $settings['enabled'] || ! NotificationSettings::for($store)->enabled('review_request')
            || ! app(\App\Billing\Billing::class)->isOpen($store)
            || $order->fulfilled_at->gt(now()->subDays($settings['request_delay_days']))) {
            return false;
        }

        // Claim first, so overlapping runs can't both send
        $claimed = Order::withoutGlobalScope('store')->whereKey($order->id)->whereNull('review_requested_at')->update(['review_requested_at' => now()]);
        if (! $claimed) {
            return false;
        }

        // Already reviewed every product in the order: nothing to ask
        $productIds = $order->items->pluck('product_id')->filter()->unique();
        $reviewed = ProductReview::withoutGlobalScope('store')->where('store_id', $store->id)
            ->where('email', mb_strtolower($order->email))->whereIn('product_id', $productIds)->distinct()->count('product_id');
        if ($productIds->isEmpty() || $reviewed >= $productIds->count()) {
            return false;
        }

        return (bool) app(OrderNotifier::class)->send($order, 'review_request');
    }
}
