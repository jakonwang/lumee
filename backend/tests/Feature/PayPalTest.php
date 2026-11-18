<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Refund;
use App\Models\ShippingRate;
use App\Models\Store;
use App\Support\Money;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PayPalTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private const API = 'https://api-m.sandbox.paypal.com';

    private array $a;

    private int $variantId;

    /** What the fake PayPal capture endpoint answers. */
    private array $captureReply = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.paypal' => ['client_id' => 'cid', 'secret' => 'sec', 'mode' => 'sandbox', 'webhook_id' => 'WH-1']]);
        Mail::fake();

        $res = $this->postJson('/api/admin/auth/register', [
            'name' => 'M', 'email' => 'owner@shop-a.test', 'password' => 'secret-pass', 'store_name' => 'Shop A',
        ])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];

        $this->variantId = $this->as()->postJson('/api/admin/products', [
            'title' => 'Linen Shirt', 'price' => 45, 'inventory' => 20, 'status' => 'active', 'sku' => 'LS-1',
        ])->assertCreated()->json('data.variants.0.id');

        Http::fake(function (HttpRequest $r) {
            $path = substr($r->url(), strlen(self::API));

            return match (true) {
                $path === '/v1/oauth2/token' => Http::response(['access_token' => 'A21', 'expires_in' => 32400]),
                $path === '/v2/checkout/orders' => Http::response(['id' => 'PP-1', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [
                    ['rel' => 'self', 'href' => self::API.'/v2/checkout/orders/PP-1'],
                    ['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PP-1'],
                ]], 201),
                $path === '/v2/checkout/orders/PP-1/capture' => $this->captureReply['capture'] ?? Http::response($this->paypalOrder(), 201),
                $path === '/v2/checkout/orders/PP-1' => Http::response($this->paypalOrder()),
                str_starts_with($path, '/v2/payments/captures/') => $this->captureReply['refund'] ?? Http::response(['id' => 'RF-1', 'status' => 'COMPLETED'], 201),
                $path === '/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => $r['transmission_sig'] === 'good' ? 'SUCCESS' : 'FAILURE']),
                default => Http::response(['message' => 'unexpected '.$path], 500),
            };
        });
    }

    private function as(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->a['token'], 'X-Store-Id' => (string) $this->a['store_id'], 'Accept' => 'application/json']);
    }

    /** A completed PayPal order whose capture matches our latest order (or $value). */
    private function paypalOrder(?string $value = null): array
    {
        $order = Order::withoutGlobalScope('store')->latest('id')->first();

        return ['id' => 'PP-1', 'status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [[
            'id' => 'CAP-1', 'status' => 'COMPLETED',
            'amount' => ['currency_code' => 'USD', 'value' => $value ?? Money::format($order?->total)],
        ]]]]]];
    }

    private function checkout(string $gateway = 'paypal'): \Illuminate\Testing\TestResponse
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $cart = $this->post(self::HOST.'/cart/add', ['variant_id' => $this->variantId, 'quantity' => 2])->getCookie('lumee_cart', false)->getValue();
        $rate = ShippingRate::withoutGlobalScope('store')->where('price', '>', 0)->first();

        return $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/checkout', [
            'email' => 'buyer@example.com', 'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'address1' => '1 Main St', 'city' => 'Austin', 'zip' => '73301', 'country' => 'US',
            'shipping_rate_id' => $rate->id, 'payment_gateway' => $gateway,
        ]);
    }

    private function order(): Order
    {
        return Order::withoutGlobalScope('store')->latest('id')->firstOrFail();
    }

    private function webhook(array $event, string $sig = 'good'): \Illuminate\Testing\TestResponse
    {
        return $this->call('POST', '/webhooks/paypal', [], [], [], [
            'HTTP_PAYPAL_TRANSMISSION_SIG' => $sig, 'HTTP_PAYPAL_TRANSMISSION_ID' => 't1', 'HTTP_PAYPAL_TRANSMISSION_TIME' => now()->toIso8601String(),
            'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA', 'HTTP_PAYPAL_CERT_URL' => 'https://api.sandbox.paypal.com/cert.pem', 'CONTENT_TYPE' => 'application/json',
        ], json_encode($event));
    }

    public function test_checkout_redirects_to_paypal_and_return_captures_the_payment(): void
    {
        $this->checkout()->assertRedirect('https://www.sandbox.paypal.com/checkoutnow?token=PP-1');
        $order = $this->order();
        $this->assertSame('pending', $order->financial_status);
        $this->assertSame('PP-1', $order->payment_reference);

        // Itemised order whose breakdown adds up to the total
        Http::assertSent(function (HttpRequest $r) use ($order) {
            if ($r->url() !== self::API.'/v2/checkout/orders') {
                return false;
            }
            $unit = $r['purchase_units'][0];
            $b = $unit['amount']['breakdown'];

            return $r->hasHeader('PayPal-Request-Id', 'order-'.$order->token)
                && $unit['amount'] === ['currency_code' => 'USD', 'value' => Money::format($order->total), 'breakdown' => $b]
                && $b['item_total']['value'] === '90.00' && $b['shipping']['value'] === Money::format($order->shipping_total)
                && $unit['items'][0]['quantity'] === '2' && $unit['items'][0]['sku'] === 'LS-1'
                && $unit['custom_id'] === (string) $order->id && $unit['invoice_id'] === $order->token
                && $r['payment_source']['paypal']['experience_context']['return_url'] === $order->store->storefrontUrl()."/checkout/paypal/return?order={$order->token}";
        });

        $this->get(self::HOST."/checkout/paypal/return?order={$order->token}&token=PP-1")->assertRedirect("/orders/{$order->token}?paid=1");
        $order->refresh();
        $this->assertSame('paid', $order->financial_status);
        $this->assertSame('CAP-1', $order->payment_reference);

        // Coming back again doesn't capture twice
        $this->get(self::HOST."/checkout/paypal/return?order={$order->token}")->assertRedirect("/orders/{$order->token}?paid=1");
        Http::assertSentCount(3);   // token, create, capture
    }

    public function test_amount_mismatch_or_unapproved_order_is_not_marked_paid(): void
    {
        $this->checkout();
        $order = $this->order();

        $this->captureReply['capture'] = Http::response($this->paypalOrder('0.01'), 201);
        $this->get(self::HOST."/checkout/paypal/return?order={$order->token}")
            ->assertRedirect("/orders/{$order->token}")->assertSessionHas('error');
        $this->assertSame('pending', $order->fresh()->financial_status);

        $this->captureReply['capture'] = Http::response(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'ORDER_NOT_APPROVED']]], 422);
        $this->get(self::HOST."/checkout/paypal/return?order={$order->token}")->assertSessionHas('error');
        $this->assertSame('pending', $order->fresh()->financial_status);

        // Already captured (e.g. by the webhook) → look the order up instead
        $this->captureReply['capture'] = Http::response(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'ORDER_ALREADY_CAPTURED']]], 422);
        $this->get(self::HOST."/checkout/paypal/return?order={$order->token}")->assertRedirect("/orders/{$order->token}?paid=1");
        $this->assertSame('CAP-1', $order->fresh()->payment_reference);

        $this->get(self::HOST.'/checkout/paypal/return?order=not-a-uuid')->assertNotFound();
    }

    public function test_webhook_captures_approved_orders_and_rejects_forgeries(): void
    {
        $this->checkout();
        $order = $this->order();
        $approved = ['event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => ['id' => 'PP-1', 'purchase_units' => [['custom_id' => (string) $order->id]]]];

        $this->webhook($approved, 'bad')->assertStatus(400);
        $this->assertSame('pending', $order->fresh()->financial_status);

        // custom_id pointing at our order but a different PayPal order → ignored
        $this->webhook(['event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => ['id' => 'PP-OTHER', 'purchase_units' => [['custom_id' => (string) $order->id]]]])->assertOk();
        $this->assertSame('pending', $order->fresh()->financial_status);

        $this->webhook($approved)->assertOk();
        $this->assertSame('paid', $order->fresh()->financial_status);
        $this->assertSame('CAP-1', $order->fresh()->payment_reference);

        // Late PAYMENT.CAPTURE.COMPLETED for the same capture is a no-op
        $this->webhook(['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => [
            ...$this->paypalOrder()['purchase_units'][0]['payments']['captures'][0], 'custom_id' => (string) $order->id,
            'supplementary_data' => ['related_ids' => ['order_id' => 'PP-1']],
        ]])->assertOk();
        $this->assertSame(1, $order->fresh()->events()->where('type', 'paid')->count());
    }

    public function test_capture_completed_webhook_marks_pending_capture_paid(): void
    {
        $this->checkout();
        $order = $this->order();
        $capture = $this->paypalOrder()['purchase_units'][0]['payments']['captures'][0];

        $this->webhook(['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => [
            ...$capture, 'custom_id' => (string) $order->id, 'supplementary_data' => ['related_ids' => ['order_id' => 'PP-1']],
        ]])->assertOk();
        $this->assertSame('paid', $order->fresh()->financial_status);
    }

    public function test_refund_goes_through_the_api_and_failures_change_nothing(): void
    {
        $this->checkout();
        $order = $this->order();
        $this->get(self::HOST."/checkout/paypal/return?order={$order->token}");

        $this->captureReply['refund'] = Http::response(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'CAPTURE_FULLY_REFUNDED', 'description' => 'The capture has already been fully refunded']]], 422);
        $this->as()->postJson("/api/admin/orders/{$order->id}/refunds", ['amount' => '10'])
            ->assertStatus(422)->assertJsonPath('errors.amount.0', 'PayPal 退款失败：The capture has already been fully refunded');
        $this->assertSame(0, Refund::withoutGlobalScope('store')->count());

        unset($this->captureReply['refund']);
        $this->as()->postJson("/api/admin/orders/{$order->id}/refunds", ['amount' => '10'])
            ->assertOk()->assertJsonPath('data.refunds.0.reference', 'RF-1')->assertJsonPath('data.refund_info.refunds_online', true);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::API.'/v2/payments/captures/CAP-1/refund'
            && $r['amount'] === ['value' => '10.00', 'currency_code' => 'USD'] && $r->hasHeader('PayPal-Request-Id'));
    }

    public function test_settings_toggle_and_unsupported_currency_hide_paypal(): void
    {
        $this->as()->getJson('/api/admin/settings/payments')->assertOk()
            ->assertJsonPath('available.paypal', true)->assertJsonPath('settings.paypal.enabled', true)->assertJsonPath('paypal_currency_ok', true);

        $this->flushHeaders();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $cart = $this->post(self::HOST.'/cart/add', ['variant_id' => $this->variantId, 'quantity' => 1])->getCookie('lumee_cart', false)->getValue();
        $this->withUnencryptedCookie('lumee_cart', $cart)->get(self::HOST.'/checkout')->assertOk()->assertSee('value="paypal"', false);

        Store::query()->update(['currency' => 'AED']);
        $this->withUnencryptedCookie('lumee_cart', $cart)->get(self::HOST.'/checkout')->assertOk()->assertDontSee('value="paypal"', false);
        $this->as()->getJson('/api/admin/settings/payments')->assertJsonPath('paypal_currency_ok', false);
        $this->checkout()->assertSessionHasErrors('payment_gateway');

        Store::query()->update(['currency' => 'USD']);
        $this->as()->putJson('/api/admin/settings/payments', ['stripe' => ['enabled' => false], 'paypal' => ['enabled' => false], 'manual' => ['enabled' => true, 'label' => 'Bank', 'instructions' => 'IBAN …']])
            ->assertOk()->assertJsonPath('settings.paypal.enabled', false);
        $this->checkout()->assertSessionHasErrors('payment_gateway');
    }
}
