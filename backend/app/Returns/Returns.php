<?php

namespace App\Returns;

use App\Models\Order;
use App\Models\ReturnRequest;
use App\Models\Store;
use App\Notifications\NotificationSettings;
use App\Notifications\ReturnMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Self-service returns: buyers request a return from their order page (within the store's window,
 * for shipped orders); the merchant approves (with return instructions) or declines, then refunds
 * through the normal refund flow. Each step emails the right person.
 */
class Returns
{
    public const DEFAULTS = ['enabled' => true, 'window_days' => 30, 'instructions' => ''];

    public static function settings(Store $store): array
    {
        return array_replace(self::DEFAULTS, $store->settings['returns'] ?? []);
    }

    /** Why the buyer can't request a return right now, or null when they can. */
    public function blocker(Order $order): ?string
    {
        $s = self::settings($order->store);
        if (! $s['enabled']) {
            return 'disabled';
        }
        if ($order->isCancelled() || $order->fulfillment_status !== 'fulfilled' || ! in_array($order->financial_status, ['paid', 'partially_refunded'], true)) {
            return 'not_eligible';
        }
        $since = $order->delivered_at ?? $order->fulfilled_at ?? $order->updated_at;
        if ($since && $since->copy()->addDays((int) $s['window_days'])->isPast()) {
            return 'window_closed';
        }
        if (ReturnRequest::where('order_id', $order->id)->whereIn('status', ['requested', 'approved'])->exists()) {
            return 'open_request';
        }

        return null;
    }

    /** Items still returnable: bought − refunded − already in an approved/completed return. */
    public function returnable(Order $order): array
    {
        $taken = [];
        foreach (ReturnRequest::where('order_id', $order->id)->whereIn('status', ['approved', 'completed'])->get() as $r) {
            foreach ($r->items as $i) {
                $taken[$i['order_item_id']] = ($taken[$i['order_item_id']] ?? 0) + $i['quantity'];
            }
        }
        $out = [];
        foreach ($order->items as $item) {
            $left = $item->quantity - $item->refunded_quantity - ($taken[$item->id] ?? 0);
            if ($left > 0) {
                $out[$item->id] = $left;
            }
        }

        return $out;
    }

    public function request(Order $order, array $items, string $reason, ?string $note): ReturnRequest
    {
        if ($why = $this->blocker($order)) {
            throw ValidationException::withMessages(['items' => __($why === 'open_request' ? 'You already have a return request for this order.' : 'This order can no longer be returned.')]);
        }
        $allowed = $this->returnable($order);
        $clean = [];
        foreach ($items as $id => $qty) {
            $qty = (int) $qty;
            if ($qty > 0 && isset($allowed[(int) $id])) {
                $clean[] = ['order_item_id' => (int) $id, 'quantity' => min($qty, $allowed[(int) $id])];
            }
        }
        if (! $clean) {
            throw ValidationException::withMessages(['items' => __('Choose at least one item to return.')]);
        }

        $r = ReturnRequest::withoutGlobalScope('store')->create([
            'store_id' => $order->store_id, 'order_id' => $order->id, 'status' => 'requested',
            'reason' => $reason, 'customer_note' => $note ? mb_substr($note, 0, 1000) : null, 'items' => $clean,
        ]);
        $order->log('return', __('买家申请退货（:p0 件）', ['p0' => array_sum(array_column($clean, 'quantity'))]));
        $this->mail($order, $r, 'merchant');

        return $r;
    }

    public function decide(ReturnRequest $r, string $status, ?string $note, ?string $instructions): ReturnRequest
    {
        $allowed = ['requested' => ['approved', 'declined'], 'approved' => ['completed', 'declined']][$r->status] ?? [];
        if (! in_array($status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => __('这个退货申请当前不能改成这个状态')]);
        }
        $r->fill([
            'status' => $status,
            'merchant_note' => $note ?? $r->merchant_note,
            'return_instructions' => $status === 'approved' ? ($instructions ?: self::settings($r->order->store)['instructions'] ?: null) : $r->return_instructions,
            'decided_at' => now(),
            'decided_by' => request()?->user()?->id,
        ])->save();

        $label = ['approved' => __('已同意退货申请'), 'declined' => __('已拒绝退货申请'), 'completed' => __('已收到退货')][$status];
        $r->order->log('return', $label);
        if (in_array($status, ['approved', 'declined'], true)) {
            $this->mail($r->order, $r, 'customer');
        }

        return $r;
    }

    private function mail(Order $order, ReturnRequest $r, string $to): void
    {
        try {
            $store = $order->store;
            $address = $to === 'merchant' ? NotificationSettings::for($store->load('owner'))->get('merchant_email') : $order->email;
            // Merchant alerts in the owner's admin language, buyer emails in the language they shopped in
            $locale = $to === 'merchant' ? ($store->owner?->locale ?: 'zh_CN') : ($order->locale ?: \App\I18n\Localization::storeDefault($store));
            if ($address) {
                Mail::to($address)->locale($locale)->send(new ReturnMail($r->fresh('order.items'), $store, $to));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
