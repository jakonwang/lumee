<?php

namespace App\Payments;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Stripe Checkout (hosted payment page) — card data never touches our servers (PCI SAQ A).
 * Payment is confirmed by the checkout.session.completed webhook, not by the redirect.
 *
 * With Stripe Connect on (services.stripe.connect) this is a destination charge: the money
 * goes to the store's own Express account, on_behalf_of that account, and the platform's
 * transaction fee is kept as an application fee. Without Connect (local dev) every payment
 * lands in the platform account.
 */
class StripeGateway implements PaymentGateway
{
    public function key(): string
    {
        return 'stripe';
    }

    public function label(): string
    {
        // "Card, Klarna, Alipay" when the store offers extra methods through Stripe
        $current = app(\App\Tenancy\CurrentStore::class);
        $extra = $current->check() ? PaymentSettings::for($current->getOrFail())->stripeMethods() : [];
        if (! $extra) {
            return __('Credit / debit card');
        }

        return implode(', ', [__('Card'), ...array_map(fn ($m) => PaymentSettings::STRIPE_METHODS[$m][0], $extra)]);
    }

    public function isAvailable(): bool
    {
        return filled(config('services.stripe.secret'));
    }

    public function start(Order $order, string $storefrontUrl): ?string
    {
        $currency = strtolower($order->currency);
        $params = [
            'mode' => 'payment',
            'customer_email' => $order->email,
            'client_reference_id' => (string) $order->id,
            'metadata[order_id]' => $order->id,
            'metadata[store_id]' => $order->store_id,
            'payment_intent_data[metadata][order_id]' => $order->id,
            'success_url' => "{$storefrontUrl}/orders/{$order->token}?paid=1",
            'cancel_url' => "{$storefrontUrl}/orders/{$order->token}",
        ];

        // Subscriptions: keep the card on a Stripe customer so renewals can be charged off-session
        $subscribing = $order->items->contains(fn ($i) => $i->plan);
        if ($subscribing) {
            $params['customer_creation'] = 'always';
            $params['payment_intent_data[setup_future_usage]'] = 'off_session';
            $params['metadata[subscription]'] = '1';
        }

        $connect = app(StripeConnect::class);
        if ($connect->enabled()) {
            $store = $order->store()->first();
            if (! $store || ! $connect->ready($store)) {
                throw new RuntimeException(__('Card payments are not available for this store yet. Please choose another payment method.'));
            }
            $rate = app(\App\Billing\Billing::class)->limits($store)['fee_bp'];
            $fee = (int) round($order->total * $rate / 10000);
            $params['payment_intent_data[transfer_data][destination]'] = $store->stripe_account_id;
            $params['payment_intent_data[on_behalf_of]'] = $store->stripe_account_id;
            if ($fee > 0) {
                $params['payment_intent_data[application_fee_amount]'] = $fee;
            }
            $order->forceFill(['stripe_account' => $store->stripe_account_id, 'application_fee' => $fee])->save();
        }

        if ($order->discount_total > 0) {
            // Stripe line items can't be negative, so a discounted order is charged as one line
            $count = $order->items->sum('quantity');
            $lines = [["Order {$order->name()} ({$count} ".($count === 1 ? 'item' : 'items').", discount {$order->discount_code} applied)", $order->total, 1]];
        } else {
            $lines = $order->items->map(fn ($i) => [$i->title, $i->price, $i->quantity])->all();
            if ($order->shipping_total > 0) {
                $lines[] = ['Shipping: '.($order->shipping_method['name'] ?? ''), $order->shipping_total, 1];
            }
            if ($order->tax_total > 0 && ! $order->taxes_included) {
                $lines[] = [($order->tax_lines[0]['name'] ?? 'Tax').' '.($order->tax_lines[0]['rate'] ?? '').'%', $order->tax_total, 1];
            }
        }

        foreach (array_values($lines) as $n => [$name, $amount, $qty]) {
            $params["line_items[{$n}][quantity]"] = $qty;
            $params["line_items[{$n}][price_data][currency]"] = $currency;
            $params["line_items[{$n}][price_data][unit_amount]"] = $amount;
            $params["line_items[{$n}][price_data][product_data][name]"] = $name;
        }

        // Extra methods the store turned on (Klarna, Alipay…) — only those that take this currency
        $methods = $subscribing ? [] : PaymentSettings::for($order->store()->first())->stripeMethods();   // saved-card renewals need cards
        $base = $params;
        if ($methods) {
            foreach (['card', ...$methods] as $i => $m) {
                $params["payment_method_types[{$i}]"] = $m;
            }
            if (in_array('wechat_pay', $methods, true)) {
                $params['payment_method_options[wechat_pay][client]'] = 'web';
            }
        }

        $response = Http::asForm()
            ->withToken(config('services.stripe.secret'))
            ->withHeaders(['Idempotency-Key' => 'order-'.$order->token.($methods ? '-m' : '')])
            ->post('https://api.stripe.com/v1/checkout/sessions', $params);

        // A method not activated in the Stripe dashboard makes Stripe refuse the session: fall back to
        // the dashboard's default methods, so the buyer can still pay
        if ($methods && $response->failed()) {
            report(new RuntimeException('Stripe rejected payment methods ['.implode(',', $methods).']: '.$response->body()));
            $response = Http::asForm()
                ->withToken(config('services.stripe.secret'))
                ->withHeaders(['Idempotency-Key' => 'order-'.$order->token])
                ->post('https://api.stripe.com/v1/checkout/sessions', $base);
        }

        if ($response->failed()) {
            report(new RuntimeException('Stripe session failed: '.$response->body()));
            throw new RuntimeException(__('Payment could not be started. Please try again.'));
        }

        $order->update(['payment_reference' => $response->json('id')]);

        return $response->json('url');
    }

    public function refundsOnline(): bool
    {
        return true;
    }

    /** https://docs.stripe.com/api/refunds/create — idempotent per refund attempt. */
    public function refund(Order $order, int $amount, string $idempotencyKey): ?string
    {
        $params = [
            'payment_intent' => $this->paymentIntentId($order),
            'amount' => $amount,
            'metadata[order_id]' => $order->id,
        ];
        if ($order->stripe_account) {
            // Connect: pull the money back from the store's account and return our fee share
            $params['reverse_transfer'] = 'true';
            $params['refund_application_fee'] = 'true';
        }
        $response = $this->api()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('https://api.stripe.com/v1/refunds', $params);

        if ($response->failed()) {
            report(new RuntimeException('Stripe refund failed: '.$response->body()));
            throw new RuntimeException(__('Stripe 退款失败：').($response->json('error.message') ?? __('请稍后重试')));
        }

        return $response->json('id');
    }

    /**
     * Saved card of a paid order: [customer id, payment method id] (for subscription renewals).
     *
     * @return array{0: string, 1: string}|null
     */
    public function savedCard(Order $order): ?array
    {
        try {
            $pi = $this->api()->get('https://api.stripe.com/v1/payment_intents/'.$this->paymentIntentId($order))->json();
        } catch (\Throwable) {
            return null;
        }
        $customer = is_array($pi['customer'] ?? null) ? $pi['customer']['id'] : ($pi['customer'] ?? null);
        $pm = is_array($pi['payment_method'] ?? null) ? $pi['payment_method']['id'] : ($pi['payment_method'] ?? null);

        return $customer && $pm ? [$customer, $pm] : null;
    }

    /** Charge a saved card without the buyer present. Returns the PaymentIntent id; throws with the decline reason. */
    public function chargeSaved(Order $order, string $customer, string $paymentMethod): string
    {
        $params = [
            'amount' => $order->total,
            'currency' => strtolower($order->currency),
            'customer' => $customer,
            'payment_method' => $paymentMethod,
            'off_session' => 'true',
            'confirm' => 'true',
            'description' => "Order {$order->name()} (subscription renewal)",
            'metadata[order_id]' => $order->id,
            'metadata[store_id]' => $order->store_id,
        ];
        $connect = app(StripeConnect::class);
        $store = $order->store()->first();
        if ($connect->enabled() && $store?->stripe_account_id) {
            $fee = (int) round($order->total * app(\App\Billing\Billing::class)->limits($store)['fee_bp'] / 10000);
            $params['transfer_data[destination]'] = $store->stripe_account_id;
            $params['on_behalf_of'] = $store->stripe_account_id;
            if ($fee > 0) {
                $params['application_fee_amount'] = $fee;
            }
            $order->forceFill(['stripe_account' => $store->stripe_account_id, 'application_fee' => $fee])->save();
        }
        $res = $this->api()->withHeaders(['Idempotency-Key' => 'renewal-'.$order->token])->post('https://api.stripe.com/v1/payment_intents', $params);
        if ($res->failed() || $res->json('status') !== 'succeeded') {
            throw new RuntimeException((string) ($res->json('error.message') ?? $res->json('last_payment_error.message') ?? __('The card was declined.')));
        }

        return (string) $res->json('id');
    }

    /** Orders store the PaymentIntent once paid; older ones may still hold the Checkout Session id. */
    private function paymentIntentId(Order $order): string
    {
        $ref = (string) $order->payment_reference;
        if (str_starts_with($ref, 'pi_')) {
            return $ref;
        }
        if (str_starts_with($ref, 'cs_')) {
            $intent = $this->api()->get("https://api.stripe.com/v1/checkout/sessions/{$ref}")->json('payment_intent');
            if (is_string($intent) && $intent !== '') {
                return $intent;
            }
        }

        throw new RuntimeException(__('找不到这笔订单的 Stripe 付款记录，无法自动退款'));
    }

    private function api(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::asForm()->withToken((string) config('services.stripe.secret'))->timeout(20);
    }

    /**
     * Verify a Stripe webhook signature (Stripe-Signature header) and return the decoded event.
     * See https://docs.stripe.com/webhooks#verify-manually
     */
    public static function verifyWebhook(string $payload, ?string $header, string $secret, int $tolerance = 300): ?array
    {
        if (! $header || ! $secret) {
            return null;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($k === 't') {
                $timestamp = (int) $v;
            } elseif ($k === 'v1') {
                $signatures[] = $v;
            }
        }

        if (! $timestamp || abs(time() - $timestamp) > $tolerance) {
            return null;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        foreach ($signatures as $sig) {
            if (hash_equals($expected, (string) $sig)) {
                return json_decode($payload, true);
            }
        }

        return null;
    }
}
