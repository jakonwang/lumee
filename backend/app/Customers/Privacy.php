<?php

namespace App\Customers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\PrivacyRequest;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Customer data rights (GDPR / UK GDPR / CCPA):
 *  - export: everything the store holds about one email, as JSON (profile, orders, reviews, subscriptions…).
 *  - erase:  delete the customer record and anonymize their personal data everywhere else. Orders stay
 *            (with amounts, items and the shipping country) because stores must keep sales records,
 *            but name, email, address, phone, IP and notes are removed. Refused while the customer
 *            still has an open order (unpaid or not yet shipped), so the merchant can finish it first.
 * Apps subscribed to customers/data_request and customers/redact are told, so they can do the same.
 */
class Privacy
{
    public function export(Store $store, string $email): array
    {
        $email = mb_strtolower(trim($email));
        $q = fn (string $table) => DB::table($table)->where('store_id', $store->id);
        $c = Customer::withoutGlobalScope('store')->where('store_id', $store->id)->where('email', $email)->first();
        $orders = Order::withoutGlobalScope('store')->with('items')->where('store_id', $store->id)->where('email', $email)->orderBy('id')->get();

        $this->notifyApps($store, 'customers/data_request', $c, $email, $orders->pluck('id')->all());

        return [
            'store' => $store->name,
            'generated_at' => now()->toIso8601String(),
            'customer' => $c ? [
                'email' => $c->email, 'first_name' => $c->first_name, 'last_name' => $c->last_name, 'phone' => $c->phone,
                'birthday' => $c->birthday?->toDateString(), 'default_address' => $c->default_address,
                'accepts_marketing' => (bool) $c->accepts_marketing, 'marketing_updated_at' => $c->marketing_updated_at,
                'points' => (int) ($c->points ?? 0), 'created_at' => $c->created_at,
            ] : null,
            'orders' => $orders->map(fn (Order $o) => [
                'name' => $o->name(), 'created_at' => $o->created_at, 'currency' => $o->currency, 'total' => $o->total / 100,
                'financial_status' => $o->financial_status, 'fulfillment_status' => $o->fulfillment_status,
                'shipping_address' => $o->shipping_address, 'note' => $o->note,
                'items' => $o->items->map(fn ($i) => ['title' => $i->title, 'variant' => $i->variant_title, 'quantity' => $i->quantity, 'price' => $i->price / 100])->all(),
            ])->all(),
            'reviews' => $q('product_reviews')->where('email', $email)->get(['product_id', 'author', 'rating', 'title', 'body', 'created_at'])->all(),
            'subscriptions' => $q('customer_subscriptions')->where('email', $email)->get(['status', 'interval', 'items', 'shipping_address', 'next_billing_at', 'created_at'])
                ->map(fn ($s) => [...(array) $s, 'items' => json_decode((string) $s->items, true), 'shipping_address' => json_decode((string) $s->shipping_address, true)])->all(),
            'quotes' => $q('quotes')->where('email', $email)->get(['number', 'status', 'buyer_note', 'created_at'])->all(),
            'back_in_stock_requests' => $q('stock_alerts')->where('email', $email)->get(['product_id', 'variant_id', 'notified_at', 'created_at'])->all(),
        ];
    }

    /** Why erasure must wait, or null. */
    public function blocker(Store $store, string $email): ?string
    {
        $open = Order::withoutGlobalScope('store')->where('store_id', $store->id)->where('email', mb_strtolower(trim($email)))->whereNull('cancelled_at')
            ->where(fn ($w) => $w->where('financial_status', 'pending')
                ->orWhere(fn ($x) => $x->whereIn('financial_status', ['paid', 'partially_refunded'])->where('fulfillment_status', '!=', 'fulfilled')))
            ->count();

        return $open ? __('这位客户还有 :p0 个未完成的订单（未付款或未发货），处理完后才能删除个人数据。', ['p0' => $open]) : null;
    }

    public function erase(Store $store, string $email): void
    {
        $email = mb_strtolower(trim($email));
        if ($why = $this->blocker($store, $email)) {
            throw ValidationException::withMessages(['email' => $why]);
        }
        $c = Customer::withoutGlobalScope('store')->where('store_id', $store->id)->where('email', $email)->first();
        $orders = Order::withoutGlobalScope('store')->where('store_id', $store->id)->where('email', $email)->get();
        $this->notifyApps($store, 'customers/redact', $c, $email, $orders->pluck('id')->all());

        DB::transaction(function () use ($store, $email, $c, $orders) {
            foreach ($orders as $o) {
                $a = (array) $o->shipping_address;
                $o->forceFill([
                    'email' => "erased-order-{$o->id}@privacy.invalid", 'note' => null, 'client_ip' => null, 'customer_id' => null,
                    'shipping_address' => array_filter(['country' => $a['country'] ?? null, 'province' => $a['province'] ?? null]),
                ])->saveQuietly();
                $o->log('privacy', __('按客户要求删除了订单里的个人信息'));
            }
            $s = fn (string $table) => DB::table($table)->where('store_id', $store->id);
            $s('product_reviews')->where('email', $email)->update(['email' => null, 'author' => 'Anonymous', 'customer_id' => null]);
            $s('carts')->where('email', $email)->update(['email' => null]);
            $s('email_campaign_recipients')->where('email', $email)->delete();
            $s('stock_alerts')->where('email', $email)->delete();
            $s('customer_subscriptions')->where('email', $email)->update([
                'status' => 'cancelled', 'cancelled_at' => now(), 'email' => 'erased@privacy.invalid', 'shipping_address' => json_encode([]),
                'stripe_customer' => null, 'stripe_payment_method' => null, 'customer_id' => null,
            ]);
            $s('quotes')->where('email', $email)->update(['email' => 'erased@privacy.invalid', 'shipping_address' => null, 'buyer_note' => null, 'customer_id' => null]);
            $s('b2b_applications')->where('email', $email)->update(['email' => 'erased@privacy.invalid', 'contact_name' => '—', 'phone' => null]);
            $c?->delete();   // tokens, flow enrollments and points cascade; other references are set to null
        });
    }

    /** Handle a request: export → mark done (the file is downloaded separately); erase → erase. */
    public function complete(PrivacyRequest $r): void
    {
        $store = Store::findOrFail($r->store_id);
        if ($r->type === 'erase') {
            $this->erase($store, $r->email);
            // Keep an audit trail without keeping the address itself
            $r->forceFill(['email' => 'erased:'.substr(hash('sha256', mb_strtolower($r->email)), 0, 16), 'customer_id' => null]);
        }
        $r->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
    }

    private function notifyApps(Store $store, string $topic, ?Customer $c, string $email, array $orderIds): void
    {
        rescue(fn () => app(\App\Webhooks\Webhooks::class)->dispatch($store->id, $topic, [
            'customer' => ['id' => $c?->id, 'email' => $email], 'orders_requested' => $orderIds,
        ]));
    }
}
