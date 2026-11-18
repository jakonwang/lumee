<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ShippingRate;
use App\Models\Store;
use App\Support\Money;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MorePaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private const API = 'https://api-demo.airwallex.com/api/v1';

    private array $a;

    private array $product;

    /** What GET /pa/payment_intents/int_1 returns. */
    private array $intent = ['id' => 'int_1', 'status' => 'REQUIRES_PAYMENT_METHOD'];

    /** Stripe rejects explicit payment_method_types (method not activated). */
    private bool $stripeRejectsMethods = false;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.airwallex' => ['client_id' => 'cid', 'api_key' => 'key', 'env' => 'demo', 'webhook_secret' => 'whs'],
            'services.stripe.secret' => 'sk_test_x',
        ]);
        Mail::fake();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $res = $this->postJson('/api/admin/auth/register', [
            'name' => 'M', 'email' => 'owner@shop-a.test', 'password' => 'secret-pass', 'store_name' => 'Shop A',
        ])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
        $this->product = $this->as()->postJson('/api/admin/products', ['title' => 'Gold Ring', 'price' => 50, 'inventory' => 50, 'status' => 'active', 'sku' => 'GR-1'])->json('data');

        Http::fake(function (HttpRequest $r) {
            $url = $r->url();

            return match (true) {
                $url === self::API.'/authentication/login' => Http::response(['token' => 'awx-token', 'expires_at' => now()->addMinutes(30)->toIso8601String()], 201),
                $url === self::API.'/pa/payment_intents/create' => Http::response(['id' => 'int_1', 'client_secret' => 'cs_secret', 'status' => 'REQUIRES_PAYMENT_METHOD'], 201),
                $url === self::API.'/pa/payment_intents/int_1' => Http::response($this->intent),
                $url === self::API.'/pa/refunds/create' => Http::response(['id' => 'rfd_1', 'status' => 'RECEIVED'], 201),
                $url === 'https://api.stripe.com/v1/checkout/sessions' => $this->stripeRejectsMethods && isset($r['payment_method_types[0]'])
                    ? Http::response(['error' => ['message' => 'The payment method type "klarna" is invalid.']], 400)
                    : Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1']),
                default => Http::response(['message' => 'unexpected '.$url], 500),
            };
        });
    }

    private function as(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->a['token'], 'X-Store-Id' => (string) $this->a['store_id'], 'Accept' => 'application/json']);
    }

    private function checkout(string $gateway): \Illuminate\Testing\TestResponse
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->defaultCookies = $this->unencryptedCookies = [];
        $cart = $this->post(self::HOST.'/cart/add', ['variant_id' => $this->product['variants'][0]['id'], 'quantity' => 1])->getCookie('lumee_cart', false)->getValue();

        return $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/checkout', [
            'email' => 'buyer@example.com', 'first_name' => 'Ada', 'last_name' => 'L', 'address1' => '1 St', 'city' => 'X', 'zip' => '1', 'country' => 'US',
            'shipping_rate_id' => ShippingRate::withoutGlobalScope('store')->where('price', '>', 0)->value('id'), 'payment_gateway' => $gateway,
        ]);
    }

    private function order(): Order
    {
        return Order::withoutGlobalScope('store')->latest('id')->firstOrFail();
    }

    private function webhook(array $event, string $secret = 'whs', ?int $ts = null): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($event);
        $ts ??= now()->getTimestampMs();

        return $this->call('POST', '/webhooks/airwallex', [], [], [], [
            'HTTP_X_TIMESTAMP' => (string) $ts, 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $ts.$body, $secret), 'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    public function test_airwallex_hosted_page_return_and_refund(): void
    {
        $this->as()->getJson('/api/admin/settings/payments')->assertJsonPath('available.airwallex', true)->assertJsonPath('settings.airwallex.enabled', true);

        $res = $this->checkout('airwallex');
        $order = $this->order();
        $store = Store::find($this->a['store_id']);
        $res->assertRedirect($store->storefrontUrl()."/checkout/airwallex/{$order->token}");
        $this->assertSame('int_1', $order->payment_reference);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::API.'/pa/payment_intents/create' && $r->hasHeader('Authorization', 'Bearer awx-token')
            && $r['request_id'] === 'order-'.$order->token && $r['currency'] === 'USD' && $r['amount'] == Money::format($order->total)
            && $r['order']['products'][0]['sku'] === 'GR-1' && $r['metadata']['order_id'] === (string) $order->id);

        // Hand-off page opens Airwallex's hosted checkout
        $this->get(self::HOST."/checkout/airwallex/{$order->token}")->assertOk()
            ->assertSee('Airwallex.redirectToCheckout', false)->assertSee('"intent_id":"int_1"', false)->assertSee('"env":"demo"', false);

        // Back before paying → not paid
        $this->get(self::HOST."/checkout/airwallex/return?order={$order->token}")->assertRedirect("/orders/{$order->token}")->assertSessionHas('error');
        // Paid but a different amount → still not paid
        $this->intent = ['id' => 'int_1', 'status' => 'SUCCEEDED', 'amount' => 1.00, 'currency' => 'USD'];
        $this->get(self::HOST."/checkout/airwallex/return?order={$order->token}")->assertSessionHas('error');
        $this->intent = ['id' => 'int_1', 'status' => 'SUCCEEDED', 'amount' => (float) Money::format($order->total), 'currency' => 'USD'];
        $this->get(self::HOST."/checkout/airwallex/return?order={$order->token}")->assertRedirect("/orders/{$order->token}?paid=1");
        $this->assertSame('paid', $order->fresh()->financial_status);

        // Refund goes back through Airwallex
        $this->as()->postJson("/api/admin/orders/{$order->id}/refunds", ['amount' => '10'])->assertOk()
            ->assertJsonPath('data.refunds.0.reference', 'rfd_1')->assertJsonPath('data.refund_info.refunds_online', true);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::API.'/pa/refunds/create' && $r['payment_intent_id'] === 'int_1' && $r['amount'] == 10.0 && filled($r['request_id']));
    }

    public function test_airwallex_webhook_is_signed_and_checked(): void
    {
        $this->checkout('airwallex');
        $order = $this->order();
        $succeeded = ['name' => 'payment_intent.succeeded', 'data' => ['object' => [
            'id' => 'int_1', 'status' => 'SUCCEEDED', 'amount' => (float) Money::format($order->total), 'currency' => 'USD', 'metadata' => ['order_id' => (string) $order->id],
        ]]];

        $this->webhook($succeeded, 'wrong')->assertStatus(400);
        $this->webhook($succeeded, ts: now()->subHour()->getTimestampMs())->assertStatus(400);   // replay
        $forged = $succeeded;
        $forged['data']['object']['id'] = 'int_other';
        $this->webhook($forged)->assertOk();
        $this->assertSame('pending', $order->fresh()->financial_status);

        $this->webhook($succeeded)->assertOk();
        $this->assertSame(['paid', 'int_1'], [$order->fresh()->financial_status, $order->fresh()->payment_reference]);
    }

    public function test_stripe_local_methods_follow_currency_and_fall_back(): void
    {
        $this->as()->putJson('/api/admin/settings/payments', ['stripe' => ['enabled' => true, 'methods' => ['bitcoin']], 'manual' => ['enabled' => false]])->assertStatus(422);
        $this->as()->putJson('/api/admin/settings/payments', ['stripe' => ['enabled' => true, 'methods' => ['klarna', 'ideal', 'wechat_pay']], 'manual' => ['enabled' => false]])
            ->assertOk()->assertJsonPath('settings.stripe.methods', ['klarna', 'ideal', 'wechat_pay'])
            ->assertJsonPath('stripe_methods.2.key', 'ideal')->assertJsonPath('stripe_methods.2.supported', false);   // iDEAL is EUR-only

        $this->flushHeaders();
        $cart = $this->post(self::HOST.'/cart/add', ['variant_id' => $this->product['variants'][0]['id'], 'quantity' => 1])->getCookie('lumee_cart', false)->getValue();
        $this->withUnencryptedCookie('lumee_cart', $cart)->get(self::HOST.'/checkout')->assertSee('Card, Klarna, WeChat Pay');

        $this->checkout('stripe')->assertRedirect('https://checkout.stripe.com/c/pay/cs_1');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/checkout/sessions') && $r['payment_method_types[0]'] === 'card'
            && $r['payment_method_types[1]'] === 'klarna' && $r['payment_method_types[2]'] === 'wechat_pay' && ! isset($r['payment_method_types[3]'])
            && $r['payment_method_options[wechat_pay][client]'] === 'web');

        // Not activated in the Stripe dashboard → retried with Stripe's default methods, buyer still gets to pay
        $this->stripeRejectsMethods = true;
        $this->checkout('stripe')->assertRedirect('https://checkout.stripe.com/c/pay/cs_1');
        $this->assertCount(2, Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), '/checkout/sessions') && str_contains($r->header('Idempotency-Key')[0] ?? '', $this->order()->token)));
    }
}
