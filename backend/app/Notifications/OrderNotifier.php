<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\OrderNotification;

/**
 * Decides which emails an order event triggers.
 *
 *   Bank transfer:  placed → order confirmation (with payment instructions) + merchant alert
 *                   marked paid → payment received
 *   Card (Stripe):  paid → order confirmation + merchant alert   (no email while awaiting the card payment)
 *   Any:            fulfilled → shipping confirmation
 */
class OrderNotifier
{
    /** While > 0, automatic emails are skipped (e.g. an order the merchant created without notifying the buyer). */
    private static int $muted = 0;

    public static function quietly(callable $fn): mixed
    {
        self::$muted++;
        try {
            return $fn();
        } finally {
            self::$muted--;
        }
    }

    public function orderPlaced(Order $order): void
    {
        if (in_array($order->payment_gateway, ['manual', 'terms'], true)) {
            $this->send($order, 'order_confirmation');
            $this->send($order, 'merchant_new_order');
        }
    }

    public function orderPaid(Order $order): void
    {
        if (in_array($order->payment_gateway, ['manual', 'terms'], true)) {
            $this->send($order, 'payment_received');
        } else {
            $this->send($order, 'order_confirmation');
            $this->send($order, 'merchant_new_order');
        }
    }

    public function orderFulfilled(Order $order): void
    {
        $this->send($order, ($order->shipping_method['type'] ?? null) === 'pickup' ? 'pickup_ready' : 'shipping_confirmation');
    }

    /** Queue one email. $force bypasses the store's on/off switch (manual "resend"). */
    public function send(Order $order, string $type, bool $force = false): ?OrderNotification
    {
        if (! $force && self::$muted > 0) {
            return null;
        }
        if (! $force && in_array($type, Sms::TYPES, true)) {
            try {
                app(Sms::class)->forOrder($order, $type);   // text message alongside the email (own on/off settings)
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $settings = NotificationSettings::for($order->store()->with('owner')->first());
        if (! $force && ! $settings->enabled($type)) {
            return null;
        }

        $recipient = NotificationSettings::TYPES[$type][0] === 'merchant'
            ? $settings->get('merchant_email')
            : $order->email;
        if (! $recipient) {
            return null;
        }

        $log = OrderNotification::withoutGlobalScope('store')->create([
            'store_id' => $order->store_id,
            'order_id' => $order->id,
            'type' => $type,
            'recipient' => $recipient,
            'status' => 'queued',
        ]);

        // afterCommit: never email about an order whose transaction later rolls back
        SendOrderEmail::dispatch($log->id)->afterCommit();

        return $log;
    }
}
