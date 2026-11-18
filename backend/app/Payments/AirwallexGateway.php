<?php

namespace App\Payments;

use App\Models\Order;
use App\Support\Money;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Airwallex hosted payment page (cards, Apple/Google Pay and local methods Airwallex enables).
 * https://www.airwallex.com/docs/payments
 *
 * start():   create a PaymentIntent, then send the buyer to /checkout/airwallex/{token}, which hands
 *            over to Airwallex's hosted page (their JS SDK, redirectToCheckout)
 * return:    /checkout/airwallex/return → read the intent; SUCCEEDED + amount matches → paid
 * webhook:   payment_intent.succeeded (x-signature = HMAC-SHA256(x-timestamp + body))
 * refund():  POST /pa/refunds/create
 *
 * orders.payment_reference holds the PaymentIntent id (int_…).
 */
class AirwallexGateway implements PaymentGateway
{
    public function key(): string
    {
        return 'airwallex';
    }

    public function label(): string
    {
        return __('Card & local payment methods');
    }

    public function isAvailable(): bool
    {
        return filled(config('services.airwallex.client_id')) && filled(config('services.airwallex.api_key'));
    }

    public function demo(): bool
    {
        return config('services.airwallex.env') !== 'prod';
    }

    public function baseUrl(): string
    {
        return $this->demo() ? 'https://api-demo.airwallex.com/api/v1' : 'https://api.airwallex.com/api/v1';
    }

    public function start(Order $order, string $storefrontUrl): ?string
    {
        $order->loadMissing('items', 'store');
        $response = $this->api()->post('/pa/payment_intents/create', [
            'request_id' => 'order-'.$order->token,   // idempotent per order
            'amount' => (float) Money::format((int) $order->total),
            'currency' => $order->currency,
            'merchant_order_id' => $order->token,
            'descriptor' => mb_substr((string) $order->store?->name, 0, 32),
            'return_url' => "{$storefrontUrl}/checkout/airwallex/return?order={$order->token}",
            'metadata' => ['order_id' => (string) $order->id, 'store_id' => (string) $order->store_id],
            'order' => ['products' => $order->items->map(fn ($i) => [
                'name' => mb_substr($i->title, 0, 120), 'quantity' => $i->quantity, 'unit_price' => (float) Money::format((int) $i->price),
                'sku' => $i->sku, 'type' => 'physical',
            ])->values()->all()],
        ]);

        if ($response->failed() || ! $response->json('id')) {
            report(new RuntimeException('Airwallex intent failed: '.$response->body()));
            throw new RuntimeException(__('Payment could not be started. Please try again.'));
        }

        $order->update(['payment_reference' => $response->json('id')]);
        // Client secret is short-lived and only needed by the hand-off page
        Cache::put('airwallex-secret:'.$order->token, $response->json('client_secret'), now()->addMinutes(55));

        return "{$storefrontUrl}/checkout/airwallex/{$order->token}";
    }

    /** What the hand-off page needs to open Airwallex's hosted checkout. */
    public function handoff(Order $order, string $storefrontUrl): ?array
    {
        $secret = Cache::get('airwallex-secret:'.$order->token);
        if (! $secret || ! $order->payment_reference) {
            return null;
        }

        return [
            'env' => $this->demo() ? 'demo' : 'prod',
            'intent_id' => $order->payment_reference,
            'client_secret' => $secret,
            'currency' => $order->currency,
            'successUrl' => "{$storefrontUrl}/checkout/airwallex/return?order={$order->token}",
            'failUrl' => "{$storefrontUrl}/orders/{$order->token}",
        ];
    }

    /** Read the intent and mark the order paid when it succeeded. Safe to call repeatedly. */
    public function confirm(Order $order): bool
    {
        if ($order->isPaid()) {
            return true;
        }
        if ($order->payment_gateway !== 'airwallex' || ! str_starts_with((string) $order->payment_reference, 'int_')) {
            return false;
        }
        $intent = $this->api()->get('/pa/payment_intents/'.$order->payment_reference);
        if ($intent->failed()) {
            report(new RuntimeException('Airwallex intent read failed: '.$intent->body()));

            return false;
        }

        return $this->applyIntent($order, (array) $intent->json());
    }

    public function applyIntent(Order $order, array $intent): bool
    {
        if (($intent['status'] ?? null) !== 'SUCCEEDED' || ($intent['id'] ?? null) !== $order->payment_reference) {
            return false;
        }
        if (strtoupper((string) ($intent['currency'] ?? '')) !== $order->currency || Money::toMinor($intent['amount'] ?? 0) !== (int) $order->total) {
            report(new RuntimeException("Airwallex amount mismatch on order {$order->id}"));

            return false;
        }
        $order->markPaid($intent['id']);

        return true;
    }

    public function refundsOnline(): bool
    {
        return true;
    }

    public function refund(Order $order, int $amount, string $idempotencyKey): ?string
    {
        $response = $this->api()->post('/pa/refunds/create', [
            'request_id' => $idempotencyKey,
            'payment_intent_id' => $order->payment_reference,
            'amount' => (float) Money::format($amount),
            'reason' => 'Refund for order '.$order->name(),
        ]);
        if ($response->failed()) {
            report(new RuntimeException('Airwallex refund failed: '.$response->body()));
            throw new RuntimeException(__('Airwallex 退款失败：').($response->json('message') ?? __('请稍后重试')));
        }

        return $response->json('id');
    }

    /** https://www.airwallex.com/docs/developer-tools__listen-for-webhook-events__acknowledge-events */
    public static function validSignature(string $body, ?string $timestamp, ?string $signature): bool
    {
        $secret = (string) config('services.airwallex.webhook_secret');
        if ($secret === '' || ! $timestamp || ! $signature) {
            return false;
        }
        // Reject replays older than 10 minutes (timestamp is in milliseconds)
        if (abs(now()->getTimestampMs() - (int) $timestamp) > 600_000) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp.$body, $secret), $signature);
    }

    private function api(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())->acceptJson()->asJson()->timeout(20)->withToken($this->token());
    }

    /** Bearer token (valid ~30 minutes), cached. */
    private function token(): string
    {
        $cacheKey = 'airwallex-token:'.md5($this->baseUrl().config('services.airwallex.client_id'));
        if ($token = Cache::get($cacheKey)) {
            return $token;
        }
        $response = Http::baseUrl($this->baseUrl())->acceptJson()->timeout(20)->withHeaders([
            'x-client-id' => (string) config('services.airwallex.client_id'),
            'x-api-key' => (string) config('services.airwallex.api_key'),
        ])->post('/authentication/login');
        if ($response->failed() || ! $response->json('token')) {
            report(new RuntimeException('Airwallex auth failed: '.$response->body()));
            throw new RuntimeException(__('Payment could not be started. Please try again.'));
        }
        Cache::put($cacheKey, $response->json('token'), now()->addMinutes(25));

        return $response->json('token');
    }
}
