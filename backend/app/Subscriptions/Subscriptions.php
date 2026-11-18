<?php

namespace App\Subscriptions;

use App\Models\CustomerSubscription;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Notifications\SubscriptionMail;
use App\Payments\StripeGateway;
use App\Services\Inventory;
use App\Services\TaxService;
use App\Tenancy\CurrentStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Subscribe-and-save: a paid order with subscription items starts one subscription per delivery
 * interval; renewals create a new order at the subscription price and charge the saved card
 * (Stripe, off-session). Manual-payment subscriptions create a pending order with payment instructions.
 * A failed charge is retried every 3 days; after 3 failures the subscription is cancelled.
 */
class Subscriptions
{
    public const MAX_ATTEMPTS = 3;

    public const RETRY_DAYS = 3;

    /** Paid order → subscriptions (idempotent). */
    public function startFrom(Order $order): void
    {
        if ($order->subscription_id) {
            return;   // a renewal order, not a new subscription
        }
        $order->loadMissing('items');
        $groups = $order->items->filter(fn ($i) => $i->plan)->groupBy('plan');
        if ($groups->isEmpty() || CustomerSubscription::withoutGlobalScope('store')->where('origin_order_id', $order->id)->exists()) {
            return;
        }
        $products = Product::withoutGlobalScope('store')->whereIn('id', $order->items->pluck('product_id')->filter())->get()->keyBy('id');
        $created = [];
        foreach ($groups as $plan => $items) {
            $created[] = CustomerSubscription::withoutGlobalScope('store')->forceCreate([
                'store_id' => $order->store_id, 'token' => (string) Str::uuid(), 'customer_id' => $order->customer_id, 'email' => $order->email,
                'status' => 'active', 'interval' => $plan,
                'discount' => Plans::config($products->get($items->first()->product_id))['discount'],
                'items' => $items->map(fn ($i) => ['variant_id' => $i->variant_id, 'product_id' => $i->product_id, 'quantity' => $i->quantity,
                    'title' => $i->title, 'variant_title' => $i->variant_title])->values()->all(),
                'shipping_address' => $order->shipping_address, 'shipping_method' => $order->shipping_method,
                'currency' => $order->currency, 'payment_gateway' => $order->payment_gateway, 'locale' => $order->locale,
                'next_billing_at' => Plans::next(($order->paid_at ?? now())->toImmutable(), $plan),
                'origin_order_id' => $order->id, 'last_order_id' => $order->id, 'orders_count' => 1,
            ]);
        }
        $order->log('subscription', __('买家开始了 :p0 个订阅', ['p0' => count($created)]));
        foreach ($created as $sub) {
            $this->mail($sub, 'started');
        }
    }

    /** Due subscriptions (for the scheduler). Returns how many renewals succeeded. */
    public function renewDue(int $limit = 200): int
    {
        $ok = 0;
        $due = CustomerSubscription::withoutGlobalScope('store')->whereIn('status', ['active', 'past_due'])
            ->where('next_billing_at', '<=', now())->orderBy('next_billing_at')->limit($limit)->get();
        foreach ($due as $sub) {
            try {
                $ok += $this->renew($sub) ? 1 : 0;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $ok;
    }

    /** One renewal: build the order, take payment, move the date. */
    public function renew(CustomerSubscription $sub): bool
    {
        $store = Store::find($sub->store_id);
        if (! $store) {
            return false;
        }
        $current = app(CurrentStore::class);
        $previous = $current->check() ? $current->get() : null;
        $current->set($store);
        try {
            try {
                $order = $this->buildOrder($sub, $store);
            } catch (RuntimeException $e) {
                return $this->failed($sub, $e->getMessage());
            }

            if ($sub->payment_gateway === 'stripe') {
                try {
                    $card = $this->card($sub);
                    $pi = app(StripeGateway::class)->chargeSaved($order, $card[0], $card[1]);
                } catch (RuntimeException $e) {
                    $this->discard($order);

                    return $this->failed($sub, $e->getMessage());
                }
                $this->reserve($order);
                $order->markPaid($pi);
            } elseif ($sub->payment_gateway === 'test') {
                $this->reserve($order);
                $order->markPaid('test-renewal');
            } else {
                // Manual payment: pending order with the store's payment instructions
                $this->reserve($order);
                app(\App\Notifications\OrderNotifier::class)->orderPlaced($order);
            }

            $sub->forceFill([
                'status' => 'active', 'failed_attempts' => 0, 'last_error' => null, 'last_order_id' => $order->id,
                'orders_count' => $sub->orders_count + 1, 'next_billing_at' => Plans::next(now()->toImmutable(), $sub->interval),
            ])->save();
            app(\App\Webhooks\Webhooks::class)->orderEvent($order->fresh(), 'orders/create');

            return true;
        } finally {
            $previous ? $current->set($previous) : $current->set(null);
        }
    }

    public function skip(CustomerSubscription $sub): void
    {
        $sub->forceFill(['next_billing_at' => Plans::next(($sub->next_billing_at ?? now())->toImmutable(), $sub->interval)])->save();
    }

    public function pause(CustomerSubscription $sub): void
    {
        $sub->forceFill(['status' => 'paused'])->save();
    }

    public function resume(CustomerSubscription $sub): void
    {
        $next = $sub->next_billing_at && $sub->next_billing_at->isFuture() ? $sub->next_billing_at : Plans::next(now()->toImmutable(), $sub->interval);
        $sub->forceFill(['status' => 'active', 'next_billing_at' => $next, 'failed_attempts' => 0])->save();
    }

    public function cancel(CustomerSubscription $sub, bool $notify = true): void
    {
        $sub->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
        if ($notify) {
            $this->mail($sub, 'cancelled');
        }
    }

    /** Pending renewal order at the subscription price (stock is reserved only once paid). */
    private function buildOrder(CustomerSubscription $sub, Store $store): Order
    {
        return DB::transaction(function () use ($sub, $store) {
            $variants = ProductVariant::with('product')->whereIn('id', array_column($sub->items, 'variant_id'))->lockForUpdate()->get()->keyBy('id');
            $lines = [];
            $subtotal = 0;
            foreach ($sub->items as $i) {
                $v = $variants->get($i['variant_id']);
                if (! $v || $v->product?->status !== 'active') {
                    throw new RuntimeException(__(':title is no longer available.', ['title' => $i['title']]));
                }
                if ($v->inventory_quantity < $i['quantity']) {
                    throw new RuntimeException(__(':title is out of stock.', ['title' => $i['title']]));
                }
                $raw = (int) $v->getRawOriginal('price');
                $unit = $sub->discount > 0 ? (int) round($raw * (100 - (float) $sub->discount) / 100) : $raw;
                $lines[] = [$v, (int) $i['quantity'], $unit];
                $subtotal += $unit * $i['quantity'];
            }
            $shipping = (int) ($sub->shipping_method['price'] ?? 0);
            $a = $sub->shipping_address ?? [];
            $taxable = array_sum(array_map(fn ($l) => $l[0]->product->taxable ? $l[2] * $l[1] : 0, $lines));
            $tax = app(TaxService::class)->calculate($store, $taxable, $shipping, $a['country'] ?? null, $a['province'] ?? null, $a);
            $total = $subtotal + $shipping + ($tax['included'] ? 0 : $tax['amount']);

            $locked = Store::whereKey($store->id)->lockForUpdate()->first();
            $number = max(1000, (int) Order::where('store_id', $locked->id)->max('number')) + 1;
            $order = Order::create([
                'number' => $number, 'token' => (string) Str::uuid(), 'customer_id' => $sub->customer_id, 'email' => $sub->email,
                'currency' => $sub->currency, 'locale' => $sub->locale, 'subtotal' => $subtotal, 'shipping_total' => $shipping,
                'discount_total' => 0, 'tax_total' => $tax['amount'], 'taxes_included' => $tax['included'], 'tax_lines' => $tax['lines'] ?: null,
                'total' => $total, 'shipping_address' => $a, 'shipping_method' => $sub->shipping_method,
                'payment_gateway' => $sub->payment_gateway, 'financial_status' => 'pending', 'fulfillment_status' => 'unfulfilled',
            ]);
            $order->forceFill(['source' => 'subscription', 'subscription_id' => $sub->id])->save();
            foreach ($lines as [$v, $qty, $unit]) {
                $order->items()->create([
                    'product_id' => $v->product_id, 'variant_id' => $v->id, 'title' => $v->product->title,
                    'variant_title' => $v->title === 'Default' ? null : $v->title, 'sku' => $v->sku,
                    'image' => $v->image?->url() ?? $v->product->featured_image,
                    'price' => $unit, 'quantity' => $qty, 'total' => $unit * $qty, 'plan' => $sub->interval,
                ]);
            }
            $order->log('placed', __('订阅自动续订（第 :p0 次）', ['p0' => $sub->orders_count + 1]));

            return $order->load('items');
        });
    }

    private function reserve(Order $order): void
    {
        foreach ($order->items as $item) {
            Inventory::change($item->variant_id, -$item->quantity, 'order', $order->id);
        }
    }

    /** The charge failed: the draft order goes away (nothing was reserved or paid). */
    private function discard(Order $order): void
    {
        $order->items()->delete();
        $order->events()->delete();
        $order->delete();
    }

    /** @return array{0:string,1:string} */
    private function card(CustomerSubscription $sub): array
    {
        if (! $sub->stripe_customer || ! $sub->stripe_payment_method) {
            $origin = Order::withoutGlobalScope('store')->find($sub->origin_order_id);
            $card = $origin ? app(StripeGateway::class)->savedCard($origin) : null;
            if (! $card) {
                throw new RuntimeException(__('No saved card for this subscription.'));
            }
            $sub->forceFill(['stripe_customer' => $card[0], 'stripe_payment_method' => $card[1]])->save();
        }

        return [$sub->stripe_customer, $sub->stripe_payment_method];
    }

    private function failed(CustomerSubscription $sub, string $error): bool
    {
        $attempts = $sub->failed_attempts + 1;
        if ($attempts >= self::MAX_ATTEMPTS) {
            $sub->forceFill(['failed_attempts' => $attempts, 'last_error' => mb_substr($error, 0, 300)])->save();
            $this->cancel($sub);

            return false;
        }
        $sub->forceFill([
            'status' => 'past_due', 'failed_attempts' => $attempts, 'last_error' => mb_substr($error, 0, 300),
            'next_billing_at' => now()->addDays(self::RETRY_DAYS),
        ])->save();
        $this->mail($sub, 'renewal_failed');

        return false;
    }

    private function mail(CustomerSubscription $sub, string $type): void
    {
        try {
            $store = Store::find($sub->store_id);
            $locale = $sub->locale ?: \App\I18n\Localization::storeDefault($store);
            Mail::to($sub->email)->locale($locale)->send(new SubscriptionMail($sub, $store, $type));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
