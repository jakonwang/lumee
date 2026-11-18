<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\OrderNotification;
use App\Tenancy\CurrentStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/** Sends one order email in the background and records the outcome on its OrderNotification row. */
class SendOrderEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var int[] seconds between retries (e.g. SMTP hiccups) */
    public array $backoff = [30, 120, 600];

    public function __construct(public int $notificationId) {}

    public function handle(CurrentStore $current): void
    {
        $log = OrderNotification::withoutGlobalScope('store')->find($this->notificationId);
        if (! $log || $log->status === 'sent') {
            return; // deleted, or already delivered (never send twice on a retry)
        }

        $order = Order::withoutGlobalScope('store')->with('store.owner')->find($log->order_id);
        if (! $order) {
            return;
        }

        // Queue workers have no request: set the tenant so store-scoped relations resolve correctly
        $current->set($order->store);
        $order->load('items');

        // Buyer emails in the language they shopped in; merchant alerts stay in Chinese (the admin's language)
        $merchant = NotificationSettings::TYPES[$log->type][0] === 'merchant';
        $locale = $merchant
            ? ($order->store->owner?->locale ?: 'zh_CN')   // the owner's admin language
            : ($order->locale ?: \App\I18n\Localization::storeDefault($order->store));
        $send = fn () => Mail::to($log->recipient)->locale($locale)->send(new OrderMail($order, $log->type, $order->store));
        // Buyer emails: merchant content (payment instructions…) in the buyer's language too
        $merchant ? $send() : app(\App\I18n\Localization::class)->within($order->store, $locale, $send);

        $log->forceFill(['status' => 'sent', 'sent_at' => now(), 'error' => null])->save();
    }

    public function failed(Throwable $e): void
    {
        OrderNotification::withoutGlobalScope('store')->whereKey($this->notificationId)
            ->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 480)]);
    }
}
