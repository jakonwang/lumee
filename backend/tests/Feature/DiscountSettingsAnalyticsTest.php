<?php

namespace Tests\Feature;

use App\Models\Discount;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\ShippingRate;
use App\Models\Store;
use App\Services\DnsChecker;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscountSettingsAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private array $a;

    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.payments.test_gateway' => true]);
        $this->a = $this->register('a@example.com', 'Shop A');
        $id = $this->as($this->a)->postJson('/api/admin/products', [
            'title' => 'Linen Shirt', 'price' => 50, 'inventory' => 50, 'status' => 'active',
        ])->json('data.id');
        $this->variantId = ProductVariant::withoutGlobalScope('store')->where('product_id', $id)->value('id');
    }

    private function register(string $email, string $store): array
    {
        $res = $this->postJson('/api/admin/auth/register', [
            'name' => 'M', 'email' => $email, 'password' => 'secret-pass', 'store_name' => $store,
        ])->assertCreated();

        return ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
    }

    private function as(array $m): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$m['token'], 'X-Store-Id' => (string) $m['store_id']]);
    }

    private function cart(int $qty = 1): string
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        return $this->post(self::HOST.'/cart/add', ['variant_id' => $this->variantId, 'quantity' => $qty])
            ->getCookie('lumee_cart', false)->getValue();
    }

    private function applyCode(string $cart, string $code)
    {
        return $this->withUnencryptedCookie('lumee_cart', $cart)->from(self::HOST.'/cart')->post(self::HOST.'/cart/discount', ['code' => $code]);
    }

    private function checkout(string $cart, string $email = 'buyer@example.com')
    {
        $rate = ShippingRate::withoutGlobalScope('store')->where('store_id', $this->a['store_id'])->where('price', 990)->first();

        return $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/checkout', [
            'email' => $email, 'first_name' => 'A', 'last_name' => 'B', 'address1' => '1 St', 'city' => 'X',
            'zip' => '1', 'country' => 'US', 'shipping_rate_id' => $rate->id, 'payment_gateway' => 'test',
        ]);
    }

    private function discount(array $attrs): array
    {
        return $this->as($this->a)->postJson('/api/admin/discounts', $attrs)->assertCreated()->json('data');
    }

    // ── Discounts ─────────────────────────────────────────────────────────────

    public function test_percentage_code_applies_in_cart_and_order(): void
    {
        $this->discount(['code' => 'save20', 'type' => 'percentage', 'value' => 20]);   // stored upper-case

        $cart = $this->cart(2);   // $100
        $this->applyCode($cart, ' Save20 ')->assertRedirect()->assertSessionHas('status');
        $this->withUnencryptedCookie('lumee_cart', $cart)->get(self::HOST.'/cart')->assertSee('SAVE20')->assertSee('−$20.00');
        $this->withUnencryptedCookie('lumee_cart', $cart)->get(self::HOST.'/checkout')->assertSee('−$20.00')->assertSee('$89.90');

        $this->checkout($cart)->assertRedirect();
        $order = Order::withoutGlobalScope('store')->latest('id')->first();
        $this->assertSame('SAVE20', $order->discount_code);
        $this->assertSame(2000, $order->discount_total);
        $this->assertSame(10000 - 2000 + 990, $order->total);
        $this->assertSame(1, Discount::withoutGlobalScope('store')->first()->usage_count);
    }

    public function test_fixed_and_free_shipping_codes(): void
    {
        $this->discount(['code' => 'TENOFF', 'type' => 'fixed', 'value' => 10]);
        $this->discount(['code' => 'SHIPFREE', 'type' => 'free_shipping']);

        $cart = $this->cart();
        $this->applyCode($cart, 'TENOFF');
        $this->checkout($cart);
        $this->assertSame(5000 - 1000 + 990, Order::withoutGlobalScope('store')->latest('id')->first()->total);

        $cart = $this->cart();
        $this->applyCode($cart, 'SHIPFREE');
        $this->checkout($cart, 'other@example.com');
        $order = Order::withoutGlobalScope('store')->latest('id')->first();
        $this->assertSame(0, $order->shipping_total);
        $this->assertSame(5000, $order->total);
    }

    public function test_code_rules_are_enforced(): void
    {
        $this->discount(['code' => 'BIGSPEND', 'type' => 'percentage', 'value' => 10, 'min_subtotal' => 80]);
        $this->discount(['code' => 'ONCE', 'type' => 'fixed', 'value' => 5, 'once_per_customer' => true]);
        $this->discount(['code' => 'LIMIT1', 'type' => 'fixed', 'value' => 5, 'usage_limit' => 1]);
        $this->discount(['code' => 'OLD', 'type' => 'fixed', 'value' => 5, 'starts_at' => now()->subDays(10)->toIso8601String(), 'ends_at' => now()->subDay()->toIso8601String()]);
        $this->discount(['code' => 'OFF', 'type' => 'fixed', 'value' => 5, 'active' => false]);

        $cart = $this->cart();   // $50
        $this->applyCode($cart, 'BIGSPEND')->assertSessionHasErrors(['discount_code' => 'Spend $80.00 or more to use this code.']);
        $this->applyCode($cart, 'OLD')->assertSessionHasErrors(['discount_code' => 'This discount code has expired.']);
        $this->applyCode($cart, 'OFF')->assertSessionHasErrors(['discount_code' => 'This discount code is not valid.']);
        $this->applyCode($cart, 'NOPE')->assertSessionHasErrors(['discount_code' => 'This discount code is not valid.']);

        // Usage limit: second order with the code is refused at checkout
        $this->applyCode($cart, 'LIMIT1')->assertSessionHasNoErrors();
        $this->checkout($cart)->assertRedirect();
        $cart2 = $this->cart();
        $this->applyCode($cart2, 'LIMIT1')->assertSessionHasErrors(['discount_code' => 'This discount code has reached its usage limit.']);

        // Once per customer: same email can't reuse it, a different email can
        $cart3 = $this->cart();
        $this->applyCode($cart3, 'ONCE');
        $this->checkout($cart3, 'repeat@example.com')->assertRedirect();
        $cart4 = $this->cart();
        $this->applyCode($cart4, 'ONCE');
        $this->checkout($cart4, 'repeat@example.com')->assertSessionHasErrors('discount_code');
        $this->checkout($cart4, 'new@example.com')->assertRedirect();
    }

    public function test_code_that_expires_after_applying_is_dropped_at_checkout(): void
    {
        $this->discount(['code' => 'FLASH', 'type' => 'percentage', 'value' => 50]);
        $cart = $this->cart();
        $this->applyCode($cart, 'FLASH');

        Discount::withoutGlobalScope('store')->where('code', 'FLASH')->update(['active' => false]);

        $this->withUnencryptedCookie('lumee_cart', $cart)->get(self::HOST.'/checkout')
            ->assertRedirect('/cart')->assertSessionHasErrors('discount_code');
        $this->checkout($cart)->assertRedirect();
        $this->assertSame(0, Order::withoutGlobalScope('store')->latest('id')->first()->discount_total);
    }

    public function test_discount_admin_validation_and_isolation(): void
    {
        $this->as($this->a)->postJson('/api/admin/discounts', ['code' => 'BAD CODE!', 'type' => 'percentage', 'value' => 10])->assertStatus(422);
        $this->as($this->a)->postJson('/api/admin/discounts', ['code' => 'PCT', 'type' => 'percentage', 'value' => 150])->assertStatus(422);
        $d = $this->discount(['code' => 'DUP', 'type' => 'fixed', 'value' => 5]);
        $this->as($this->a)->postJson('/api/admin/discounts', ['code' => 'dup', 'type' => 'fixed', 'value' => 5])->assertStatus(422);

        // Another store may use the same code text, but can't see/edit/use Shop A's code
        $b = $this->register('b@example.com', 'Shop B');
        $this->as($b)->postJson('/api/admin/discounts', ['code' => 'DUP', 'type' => 'fixed', 'value' => 1])->assertCreated();
        $this->as($b)->putJson("/api/admin/discounts/{$d['id']}", ['code' => 'HACK', 'type' => 'fixed', 'value' => 50])->assertNotFound();
        $this->as($b)->getJson('/api/admin/discounts')->assertJsonCount(1, 'data');

        $this->discount(['code' => 'AONLY', 'type' => 'fixed', 'value' => 5]);
        $bVariant = ProductVariant::withoutGlobalScope('store')->find(
            $this->as($b)->postJson('/api/admin/products', ['title' => 'B', 'price' => 20, 'status' => 'active', 'inventory' => 5])->json('data.variants.0.id'));
        $this->flushHeaders();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $cart = $this->post('http://shop-b.lumee.localhost/cart/add', ['variant_id' => $bVariant->id])->getCookie('lumee_cart', false)->getValue();
        $this->withUnencryptedCookie('lumee_cart', $cart)->from('http://shop-b.lumee.localhost/cart')
            ->post('http://shop-b.lumee.localhost/cart/discount', ['code' => 'AONLY'])->assertSessionHasErrors('discount_code');
    }

    // ── Store settings & domains ──────────────────────────────────────────────

    public function test_store_settings(): void
    {
        $this->as($this->a)->putJson('/api/admin/settings/store', ['name' => 'Shop Alpha', 'currency' => 'EUR', 'timezone' => 'Europe/Paris'])
            ->assertOk()->assertJsonPath('store.name', 'Shop Alpha')->assertJsonPath('store.currency', 'EUR');
        $this->as($this->a)->putJson('/api/admin/settings/store', ['name' => 'X', 'currency' => 'XYZ', 'timezone' => 'Mars/Base'])
            ->assertStatus(422)->assertJsonValidationErrors(['currency', 'timezone']);

        $this->flushHeaders();
        $this->get(self::HOST.'/products/linen-shirt')->assertSee('€50.00');
    }

    public function test_custom_domain_flow(): void
    {
        $dns = new class extends DnsChecker {
            public bool $ok = false;

            public function check(string $host): array
            {
                return ['ok' => $this->ok, 'found' => $this->ok ? ['CNAME stores.lumee.shop'] : ['A 1.2.3.4']];
            }
        };
        $this->app->instance(DnsChecker::class, $dns);

        // Validation
        $this->as($this->a)->postJson('/api/admin/domains', ['host' => 'not a domain'])->assertStatus(422);
        $this->as($this->a)->postJson('/api/admin/domains', ['host' => 'x.lumee.localhost'])->assertStatus(422);
        $this->as($this->a)->postJson('/api/admin/domains', ['host' => '127.0.0.1'])->assertStatus(422);

        $res = $this->as($this->a)->postJson('/api/admin/domains', ['host' => 'https://Shop.MyBrand.com/path'])->assertCreated();
        $domain = collect($res['data'])->firstWhere('host', 'shop.mybrand.com');
        $this->assertFalse($domain['verified']);

        // Not verified → doesn't serve the store; can't become primary
        $this->flushHeaders();
        $this->get('http://shop.mybrand.com/')->assertSee('免费开店');   // platform landing, not the store
        $this->as($this->a)->postJson("/api/admin/domains/{$domain['id']}/primary")->assertStatus(422);
        $this->as($this->a)->postJson("/api/admin/domains/{$domain['id']}/verify")->assertJsonPath('verified', false);

        // DNS correct → verified → serves the store → primary
        $dns->ok = true;
        $this->as($this->a)->postJson("/api/admin/domains/{$domain['id']}/verify")->assertJsonPath('verified', true);
        $this->flushHeaders();
        $this->get('http://shop.mybrand.com/')->assertOk()->assertSee('Linen Shirt');
        $this->as($this->a)->postJson("/api/admin/domains/{$domain['id']}/primary")->assertOk();
        $this->assertStringContainsString('shop.mybrand.com', Store::find($this->a['store_id'])->load('domains')->storefrontUrl());

        // Another store can't add, verify or delete it
        $b = $this->register('b@example.com', 'Shop B');
        $this->as($b)->postJson('/api/admin/domains', ['host' => 'shop.mybrand.com'])->assertStatus(422);
        $this->as($b)->postJson("/api/admin/domains/{$domain['id']}/verify")->assertNotFound();
        $this->as($b)->deleteJson("/api/admin/domains/{$domain['id']}")->assertNotFound();

        // Removing the primary falls back to the platform domain; platform domain can't be removed
        $res = $this->as($this->a)->deleteJson("/api/admin/domains/{$domain['id']}")->assertOk();
        $this->assertTrue(collect($res['data'])->firstWhere('type', 'platform')['is_primary']);
        $platformId = collect($res['data'])->firstWhere('type', 'platform')['id'];
        $this->as($this->a)->deleteJson("/api/admin/domains/{$platformId}")->assertStatus(422);
    }

    // ── Analytics ─────────────────────────────────────────────────────────────

    public function test_analytics_totals_series_and_isolation(): void
    {
        $this->discount(['code' => 'TEN', 'type' => 'percentage', 'value' => 10]);
        $cart = $this->cart(2);              // $100 → paid
        $this->checkout($cart);
        $cart = $this->cart();               // $50 with 10% off → paid
        $this->applyCode($cart, 'TEN');
        $this->checkout($cart, 'second@example.com');

        // An older paid order (previous period) for the comparison
        Order::withoutGlobalScope('store')->latest('id')->first()->replicate(['token', 'number'])
            ->fill(['token' => (string) \Illuminate\Support\Str::uuid(), 'number' => 2000])
            ->forceFill(['created_at' => now()->subDays(10)])->save();

        $res = $this->as($this->a)->getJson('/api/admin/analytics?days=7')->assertOk();
        $expected = (10000 + 990) + (5000 - 500 + 990);
        $res->assertJsonPath('totals.orders.value', 2)
            ->assertJsonPath('totals.revenue.value', number_format($expected / 100, 2, '.', ''))
            ->assertJsonPath('discounted_orders', 1)
            ->assertJsonCount(7, 'series')
            ->assertJsonPath('products.0.title', 'Linen Shirt')
            ->assertJsonPath('products.0.qty', 3)
            ->assertJsonPath('countries.0.code', 'US');
        $this->assertNotNull($res->json('totals.revenue.change'));   // previous-period order exists

        $b = $this->register('b@example.com', 'Shop B');
        $this->as($b)->getJson('/api/admin/analytics?days=30')->assertJsonPath('totals.orders.value', 0);
    }
}
