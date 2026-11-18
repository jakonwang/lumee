<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Order;
use App\Models\ShippingRate;
use App\Models\Store;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Webhooks\Webhooks;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OpenApiTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private const HOOK = 'https://hooks.example.com/lumee';

    private array $a;

    private array $product;

    private int $hookStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.payments.test_gateway' => true]);
        Mail::fake();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Webhooks::$resolver = fn (string $host) => match ($host) {
            'hooks.example.com' => ['93.184.216.34'],
            'internal.example.com' => ['10.0.0.5'],
            default => [],
        };
        Http::fake([self::HOOK => fn () => Http::response('ok', $this->hookStatus)]);

        $res = $this->postJson('/api/admin/auth/register', [
            'name' => 'M', 'email' => 'owner@shop-a.test', 'password' => 'secret-pass', 'store_name' => 'Shop A',
        ])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
        $this->product = $this->as()->postJson('/api/admin/products', ['title' => 'Gold Ring', 'price' => 50, 'inventory' => 10, 'status' => 'active', 'sku' => 'GR-1'])->json('data');
    }

    protected function tearDown(): void
    {
        Webhooks::$resolver = null;
        parent::tearDown();
    }

    private function as(?array $a = null): static
    {
        $a ??= $this->a;
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$a['token'], 'X-Store-Id' => (string) $a['store_id'], 'Accept' => 'application/json']);
    }

    private function api(string $token): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']);
    }

    private function token(array $scopes): string
    {
        return $this->as()->postJson('/api/admin/developers/tokens', ['name' => 'ERP', 'scopes' => $scopes])->assertCreated()->json('token');
    }

    private function buy(): Order
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->defaultCookies = $this->unencryptedCookies = [];
        $cart = $this->post(self::HOST.'/cart/add', ['variant_id' => $this->product['variants'][0]['id'], 'quantity' => 1])->getCookie('lumee_cart', false)->getValue();
        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/checkout', [
            'email' => 'buyer@example.com', 'first_name' => 'A', 'last_name' => 'B', 'address1' => '1 St', 'city' => 'X', 'zip' => '1', 'country' => 'US',
            'shipping_rate_id' => ShippingRate::withoutGlobalScope('store')->value('id'), 'payment_gateway' => 'test',
        ])->assertRedirect();

        return Order::withoutGlobalScope('store')->latest('id')->firstOrFail();
    }

    public function test_tokens_scopes_and_store_isolation(): void
    {
        $plain = $this->token(['read_products', 'write_inventory']);
        $this->assertStringStartsWith('lm_', $plain);
        $this->assertSame(hash('sha256', $plain), ApiToken::withoutGlobalScope('store')->sole()->token_hash);
        $list = $this->as()->getJson('/api/admin/developers')->assertOk();
        $this->assertStringNotContainsString($plain, $list->getContent());
        $list->assertJsonPath('tokens.0.prefix', substr($plain, 0, 10))->assertJsonPath('tokens.0.scopes', ['read_products', 'write_inventory']);

        $this->api('lm_nope')->getJson('/api/v1')->assertStatus(401)->assertJsonPath('error', 'unauthorized');
        $this->api($plain)->getJson('/api/v1')->assertOk()->assertJsonPath('data.store.name', 'Shop A')->assertJsonPath('data.api_version', 'v1');
        $this->api($plain)->getJson('/api/v1/products')->assertOk()->assertJsonPath('data.0.title', 'Gold Ring');
        $this->api($plain)->getJson('/api/v1/products/'.$this->product['id'])->assertOk()->assertJsonPath('data.variants.0.sku', 'GR-1');
        $this->api($plain)->postJson('/api/v1/products', ['title' => 'X', 'price' => 1])->assertStatus(403)->assertJsonPath('error', 'forbidden');
        $this->api($plain)->getJson('/api/v1/orders')->assertStatus(403);

        // Inventory by SKU: set, then adjust
        $this->api($plain)->postJson('/api/v1/inventory/adjust', ['sku' => 'GR-1', 'available' => 42])->assertOk()->assertJsonPath('data.available', 42);
        $this->api($plain)->postJson('/api/v1/inventory/adjust', ['variant_id' => $this->product['variants'][0]['id'], 'adjustment' => -2])->assertOk()->assertJsonPath('data.available', 40);
        $this->api($plain)->postJson('/api/v1/inventory/adjust', ['sku' => 'GR-1'])->assertStatus(422);

        // Another store's token sees nothing of ours
        $b = $this->postJson('/api/admin/auth/register', ['name' => 'B', 'email' => 'b@shop-b.test', 'password' => 'secret-pass', 'store_name' => 'Shop B'])->json();
        $other = $this->as(['token' => $b['token'], 'store_id' => $b['stores'][0]['id']])->postJson('/api/admin/developers/tokens', ['name' => 'B', 'scopes' => ['read_products', 'write_inventory']])->json('token');
        $this->api($other)->getJson('/api/v1/products/'.$this->product['id'])->assertNotFound();
        $this->api($other)->getJson('/api/v1/products')->assertJsonCount(0, 'data');
        $this->api($other)->postJson('/api/v1/inventory/adjust', ['sku' => 'GR-1', 'available' => 0])->assertNotFound();

        // Revoked → 401; paused store → 402
        $id = $this->as()->getJson('/api/admin/developers')->json('tokens.0.id');
        $this->as()->deleteJson("/api/admin/developers/tokens/{$id}")->assertOk();
        $this->api($plain)->getJson('/api/v1')->assertStatus(401);
        Store::whereKey($b['stores'][0]['id'])->update(['trial_ends_at' => now()->subDay()]);
        $this->api($other)->getJson('/api/v1')->assertStatus(402);
    }

    public function test_write_products_and_orders_use_admin_rules_in_english(): void
    {
        $plain = $this->token(['write_products', 'read_orders', 'write_orders', 'read_customers']);
        $this->api($plain)->postJson('/api/v1/products', ['price' => 1])->assertStatus(422)->assertJsonPath('message', 'The title field is required.');
        $created = $this->api($plain)->postJson('/api/v1/products', ['title' => 'Silver Cuff', 'price' => 30, 'inventory' => 5, 'status' => 'active'])
            ->assertCreated()->json('data');
        $this->api($plain)->patchJson('/api/v1/products/'.$created['id'], ['title' => 'Silver Cuff II'])->assertOk()->assertJsonPath('data.title', 'Silver Cuff II');

        $order = $this->buy();
        $this->api($plain)->getJson('/api/v1/orders')->assertOk()->assertJsonPath('data.0.id', $order->id);
        $this->api($plain)->postJson("/api/v1/orders/{$order->id}/fulfill", ['tracking_company' => 'DHL', 'tracking_number' => 'D123'])
            ->assertOk()->assertJsonPath('data.fulfillment_status', 'fulfilled');
        $this->api($plain)->getJson('/api/v1/customers')->assertOk();
        $this->api($plain)->getJson('/api/v1/customers/'.$order->customer_id)->assertOk()->assertJsonPath('data.email', 'buyer@example.com');

        $this->api($plain)->deleteJson('/api/v1/products/'.$created['id'])->assertNoContent();
    }

    public function test_webhooks_are_signed_and_cover_the_order_lifecycle(): void
    {
        $this->as()->postJson('/api/admin/developers/webhooks', ['url' => 'http://127.0.0.1/hook', 'topics' => ['orders/create']])->assertStatus(422);
        $this->as()->postJson('/api/admin/developers/webhooks', ['url' => 'https://internal.example.com/hook', 'topics' => ['orders/create']])
            ->assertStatus(422)->assertJsonValidationErrors('url');
        $this->as()->postJson('/api/admin/developers/webhooks', ['url' => self::HOOK, 'topics' => ['orders/everything']])->assertStatus(422);
        $res = $this->as()->postJson('/api/admin/developers/webhooks', ['url' => self::HOOK, 'topics' => ['orders/create', 'orders/paid', 'orders/fulfilled', 'customers/create']])->assertCreated();
        $secret = $res->json('secret');
        $this->assertSame(48, strlen($secret));
        $this->assertStringNotContainsString($secret, $this->as()->getJson('/api/admin/developers')->getContent());

        $order = $this->buy();   // test gateway pays immediately
        $topics = collect(Http::recorded(fn (HttpRequest $r) => $r->url() === self::HOOK))->map(fn ($p) => $p[0]->header('X-Lumee-Topic')[0])->all();
        $this->assertEqualsCanonicalizing(['customers/create', 'orders/create', 'orders/paid'], $topics);

        Http::assertSent(function (HttpRequest $r) use ($secret, $order) {
            if ($r->url() !== self::HOOK || $r->header('X-Lumee-Topic')[0] !== 'orders/paid') {
                return false;
            }
            $body = json_decode($r->body(), true);

            return hash_equals(base64_encode(hash_hmac('sha256', $r->body(), $secret, true)), $r->header('X-Lumee-Hmac-Sha256')[0])
                && $body['data']['id'] === $order->id && $body['data']['financial_status'] === 'paid'
                && $body['topic'] === 'orders/paid' && $r->header('X-Lumee-Event-Id')[0] === $body['id'];
        });

        $this->as()->postJson("/api/admin/orders/{$order->id}/fulfill", ['tracking_number' => 'T1'])->assertOk();
        Http::assertSent(fn (HttpRequest $r) => ($r->header('X-Lumee-Topic')[0] ?? null) === 'orders/fulfilled');
        $this->assertSame(4, WebhookDelivery::withoutGlobalScope('store')->where('status', 'success')->count());
    }

    public function test_failures_retry_then_disable_and_admin_tools(): void
    {
        $hookId = $this->as()->postJson('/api/admin/developers/webhooks', ['url' => self::HOOK, 'topics' => ['products/update']])->assertCreated()->json('data.id');
        $this->hookStatus = 500;
        $this->as()->putJson('/api/admin/products/'.$this->product['id'], ['title' => 'Gold Ring 2'])->assertOk();   // queue is sync: all retries run now

        $d = WebhookDelivery::withoutGlobalScope('store')->sole();
        $this->assertSame(['failed', 6, 500], [$d->status, $d->attempts, $d->response_code]);
        $this->assertSame(1, WebhookSubscription::withoutGlobalScope('store')->find($hookId)->failure_count);

        // Test ping and redelivery: one attempt, don't count against the subscription
        $this->as()->postJson("/api/admin/developers/webhooks/{$hookId}/test")->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.response_code', 500);
        $this->hookStatus = 200;
        $this->as()->postJson("/api/admin/developers/deliveries/{$d->id}/redeliver")->assertOk()->assertJsonPath('data.status', 'success')->assertJsonPath('data.event_id', $d->event_id);
        $this->as()->getJson("/api/admin/developers/webhooks/{$hookId}/deliveries")->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame(0, WebhookSubscription::withoutGlobalScope('store')->find($hookId)->failure_count);   // a success resets it

        // Ten failed events in a row switch it off
        WebhookSubscription::withoutGlobalScope('store')->whereKey($hookId)->update(['failure_count' => 9]);
        $this->hookStatus = 500;
        $this->as()->putJson('/api/admin/products/'.$this->product['id'], ['title' => 'Gold Ring 3'])->assertOk();
        $hook = WebhookSubscription::withoutGlobalScope('store')->find($hookId);
        $this->assertFalse($hook->enabled);
        $this->assertStringContainsString('10 failed deliveries', $hook->disabled_reason);

        // Turning it back on resets the counter; the secret can be revealed again
        $this->as()->putJson("/api/admin/developers/webhooks/{$hookId}", ['url' => self::HOOK, 'topics' => ['products/update'], 'enabled' => true])
            ->assertOk()->assertJsonPath('data.failure_count', 0)->assertJsonPath('data.disabled_reason', null);
        $this->as()->getJson("/api/admin/developers/webhooks/{$hookId}/secret")->assertOk()->assertJsonStructure(['secret']);
        $this->as()->deleteJson("/api/admin/developers/webhooks/{$hookId}")->assertOk();
    }
}
