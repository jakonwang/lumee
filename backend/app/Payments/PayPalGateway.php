<?php

namespace App\Payments;

use App\Models\Order;
use App\Support\Money;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * PayPal Checkout (Orders v2). The buyer approves on paypal.com, comes back to
 * /checkout/paypal/return and we capture the payment there. A webhook
 * (CHECKOUT.ORDER.APPROVED / PAYMENT.CAPTURE.COMPLETED) covers buyers who close
 * the tab before returning and captures that clear later (e.g. eChecks).
 *
 * orders.payment_reference holds the PayPal order id until paid, then the capture id
 * (refunds are made against the capture).
 *
 * Like Stripe this uses one platform PayPal account for now; see the README.
 */
class PayPalGateway implements PaymentGateway
{
    /** https://developer.paypal.com/reference/currency-codes/ — CNY works only for China-registered accounts. */
    public const CURRENCIES = ['AUD', 'BRL', 'CAD', 'CHF', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'HUF', 'ILS', 'JPY', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'SEK', 'SGD', 'THB', 'TWD', 'USD'];

    public function key(): string
    {
        return 'paypal';
    }

    public function label(): string
    {
        return 'PayPal';
    }

    public function isAvailable(): bool
    {
        return filled(config('services.paypal.client_id')) && filled(config('services.paypal.secret'));
    }

    public function supportsCurrency(string $currency): bool
    {
        return in_array(strtoupper($currency), self::CURRENCIES, true);
    }

    public function start(Order $order, string $storefrontUrl): ?string
    {
        if (! $this->supportsCurrency($order->currency)) {
            throw new RuntimeException(__('PayPal does not support this currency. Please choose another payment method.'));
        }
        $order->loadMissing('items', 'store');

        $response = $this->api()
            ->withHeaders(['PayPal-Request-Id' => 'order-'.$order->token])
            ->post('/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [$this->purchaseUnit($order)],
                'payment_source' => ['paypal' => ['experience_context' => [
                    'brand_name' => mb_substr((string) $order->store?->name, 0, 127) ?: 'Lumée',
                    'shipping_preference' => 'NO_SHIPPING',   // address was already collected at checkout
                    'user_action' => 'PAY_NOW',
                    'locale' => $this->locale(),
                    'return_url' => "{$storefrontUrl}/checkout/paypal/return?order={$order->token}",
                    'cancel_url' => "{$storefrontUrl}/orders/{$order->token}",
                ]]],
            ]);

        if ($response->failed()) {
            report(new RuntimeException('PayPal order failed: '.$response->body()));
            throw new RuntimeException(__('Payment could not be started. Please try again.'));
        }

        $approve = collect($response->json('links', []))->first(fn ($l) => in_array($l['rel'] ?? '', ['payer-action', 'approve'], true));
        if (! $approve) {
            report(new RuntimeException('PayPal order has no approval link: '.$response->body()));
            throw new RuntimeException(__('Payment could not be started. Please try again.'));
        }

        $order->update(['payment_reference' => $response->json('id')]);

        return $approve['href'];
    }

    /**
     * Capture an approved PayPal order and mark ours paid. Safe to call more than once
     * (buyer return + webhook): an already-captured order is looked up instead.
     * Returns true when the order is (now) paid.
     */
    public function capture(Order $order): bool
    {
        if ($order->financial_status !== 'pending' && $order->financial_status !== 'voided') {
            return $order->financial_status === 'paid' || $order->financial_status === 'partially_refunded' || $order->financial_status === 'refunded';
        }
        $paypalOrderId = (string) $order->payment_reference;
        if ($paypalOrderId === '' || $order->payment_gateway !== 'paypal') {
            return false;
        }

        $response = $this->api()
            ->withHeaders(['PayPal-Request-Id' => 'capture-'.$paypalOrderId])
            ->withBody('{}', 'application/json')
            ->post("/v2/checkout/orders/{$paypalOrderId}/capture");

        if ($response->failed()) {
            $issue = $response->json('details.0.issue');
            if ($issue !== 'ORDER_ALREADY_CAPTURED') {
                if ($issue !== 'ORDER_NOT_APPROVED') {
                    report(new RuntimeException('PayPal capture failed: '.$response->body()));
                }

                return false;
            }
            $response = $this->api()->get("/v2/checkout/orders/{$paypalOrderId}");
        }

        return $this->applyCapture($order, (array) $response->json('purchase_units.0.payments.captures.0'));
    }

    /** Mark paid when a capture is COMPLETED and matches the order's amount and currency. */
    public function applyCapture(Order $order, array $capture): bool
    {
        if (($capture['status'] ?? null) !== 'COMPLETED') {
            return false;
        }
        $amount = $capture['amount'] ?? [];
        if (($amount['currency_code'] ?? null) !== $order->currency || Money::toMinor($amount['value'] ?? 0) !== (int) $order->total) {
            report(new RuntimeException("PayPal capture amount mismatch on order {$order->id}: ".json_encode($amount)));

            return false;
        }

        $order->markPaid($capture['id']);

        return true;
    }

    public function refundsOnline(): bool
    {
        return true;
    }

    /** https://developer.paypal.com/docs/api/payments/v2/#captures_refund */
    public function refund(Order $order, int $amount, string $idempotencyKey): ?string
    {
        $captureId = $this->captureId($order);
        $response = $this->api()
            ->withHeaders(['PayPal-Request-Id' => $idempotencyKey])
            ->post("/v2/payments/captures/{$captureId}/refund", [
                'amount' => ['value' => Money::format($amount), 'currency_code' => $order->currency],
                'note_to_payer' => mb_substr(__('Refund for order :name', ['name' => $order->name()]), 0, 255),
            ]);

        if ($response->failed()) {
            report(new RuntimeException('PayPal refund failed: '.$response->body()));
            $reason = $response->json('details.0.description') ?? $response->json('message');
            throw new RuntimeException(__('PayPal 退款失败：').($reason ?: __('请稍后重试')));
        }

        return $response->json('id');
    }

    /**
     * Verify a webhook with PayPal's verify-webhook-signature API.
     * https://developer.paypal.com/api/rest/webhooks/rest/#link-verifysignature
     */
    public function verifyWebhook(array $headers, string $payload): ?array
    {
        $webhookId = (string) config('services.paypal.webhook_id');
        $event = json_decode($payload, true);
        if ($webhookId === '' || ! is_array($event) || ! $this->isAvailable()) {
            return null;
        }
        $h = fn (string $name) => $headers[$name] ?? null;
        if (! $h('paypal-transmission-sig')) {
            return null;
        }

        $response = $this->api()->post('/v1/notifications/verify-webhook-signature', [
            'auth_algo' => $h('paypal-auth-algo'),
            'cert_url' => $h('paypal-cert-url'),
            'transmission_id' => $h('paypal-transmission-id'),
            'transmission_sig' => $h('paypal-transmission-sig'),
            'transmission_time' => $h('paypal-transmission-time'),
            'webhook_id' => $webhookId,
            'webhook_event' => $event,
        ]);

        return $response->ok() && $response->json('verification_status') === 'SUCCESS' ? $event : null;
    }

    private function purchaseUnit(Order $order): array
    {
        $c = $order->currency;
        $money = fn (int $minor) => ['currency_code' => $c, 'value' => Money::format($minor)];

        $unit = [
            'reference_id' => (string) $order->id,
            'custom_id' => (string) $order->id,
            'invoice_id' => $order->token,   // unique across the platform account
            'description' => mb_substr(trim(($order->store?->name ?? '').' '.$order->name()), 0, 127),
            'amount' => $money((int) $order->total),
        ];

        // Itemised breakdown when it adds up exactly (PayPal rejects mismatches); otherwise just the total
        $items = $order->items->map(fn ($i) => [
            'name' => mb_substr($i->title.($i->variant_title ? ' — '.$i->variant_title : ''), 0, 127),
            'quantity' => (string) $i->quantity,
            'unit_amount' => $money((int) $i->price),
            'sku' => $i->sku ? mb_substr($i->sku, 0, 127) : null,
        ])->map(fn ($i) => array_filter($i, fn ($v) => $v !== null))->values();
        $itemTotal = (int) $order->items->sum(fn ($i) => $i->price * $i->quantity);
        $tax = $order->taxes_included ? 0 : (int) $order->tax_total;

        if ($itemTotal - (int) $order->discount_total + (int) $order->shipping_total + $tax === (int) $order->total) {
            $unit['amount']['breakdown'] = array_filter([
                'item_total' => $money($itemTotal),
                'shipping' => $money((int) $order->shipping_total),
                'tax_total' => $tax > 0 ? $money($tax) : null,
                'discount' => $order->discount_total > 0 ? $money((int) $order->discount_total) : null,
            ]);
            $unit['items'] = $items->all();
        }

        return $unit;
    }

    /** Paid orders store the capture id (see capture()). */
    private function captureId(Order $order): string
    {
        $ref = (string) $order->payment_reference;
        if ($ref === '' || $order->paid_at === null) {
            throw new RuntimeException(__('找不到这笔订单的 PayPal 付款记录，无法自动退款'));
        }

        return $ref;
    }

    private function locale(): string
    {
        $map = ['en' => 'en-US', 'fr' => 'fr-FR', 'de' => 'de-DE', 'es' => 'es-ES', 'it' => 'it-IT', 'pt' => 'pt-BR', 'ja' => 'ja-JP'];

        return $map[substr(app()->getLocale(), 0, 2)] ?? 'en-US';
    }

    public function baseUrl(): string
    {
        return config('services.paypal.mode') === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    private function api(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())->acceptJson()->asJson()->timeout(20)->withToken($this->accessToken());
    }

    /** OAuth client-credentials token, cached until shortly before it expires. */
    private function accessToken(): string
    {
        $id = (string) config('services.paypal.client_id');
        $cacheKey = 'paypal-token:'.md5($this->baseUrl().$id);

        if ($token = Cache::get($cacheKey)) {
            return $token;
        }
        $response = Http::baseUrl($this->baseUrl())->asForm()->timeout(20)
            ->withBasicAuth($id, (string) config('services.paypal.secret'))
            ->post('/v1/oauth2/token', ['grant_type' => 'client_credentials']);
        if ($response->failed() || ! $response->json('access_token')) {
            report(new RuntimeException('PayPal auth failed: '.$response->body()));
            throw new RuntimeException(__('Payment could not be started. Please try again.'));
        }
        Cache::put($cacheKey, $response->json('access_token'), max(60, (int) $response->json('expires_in', 3600) - 300));

        return $response->json('access_token');
    }
}
