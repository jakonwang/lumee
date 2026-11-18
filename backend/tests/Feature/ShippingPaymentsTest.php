<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ShippingPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private array $a;

    private int $productId;

    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.payments.test_gateway' => true]);
        Mail::fake();

        $res = $this->postJson('/api/admin/auth/register', [
            'name' => 'M', 'email' => 'owner@shop-a.test', 'password' => 'secret-pass', 'store_name' => 'Shop A',
        ])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];

        $product = $this->as()->postJson('/api/admin/products', [
            'title' => 'Cast Iron Pan', 'price' => 30, 'inventory' => 50, 'status' => 'active', 'weight' => 400,
        ])->assertCreated();
        $this->productId = $product->json('data.id');
        $this->variantId = $product->json('data.variants.0.id');
    }

    private function as(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->a['token'], 'X-Store-Id' => (string) $this->a['store_id'], 'Accept' => 'application/json']);
    }

    private function cart(int $qty): string
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        return $this->post(self::HOST.'/cart/add', ['variant_id' => $this->variantId, 'quantity' => $qty])
            ->getCookie('lumee_cart', false)->getValue();
    }

    private function rates(string $cart, string $country): array
    {
        return $this->withCredentials()->withUnencryptedCookie('lumee_cart', $cart)
            ->getJson(self::HOST.'/checkout/shipping-rates?country='.$country)->assertOk()->json('rates');
    }

    /** US zone with weight-based rates + the default worldwide zone (ids kept). */
    private function setupZones(): array
    {
        $world = $this->as()->getJson('/api/admin/settings/shipping')->assertOk()->json('zones.0');

        return $this->as()->putJson('/api/admin/settings/shipping', ['zones' => [
            ['name' => 'United States', 'countries' => ['US'], 'rates' => [
                ['name' => 'US Light', 'description' => '2–4 days', 'price' => '5', 'condition' => 'weight', 'min' => 0, 'max' => 1000],
                ['name' => 'US Heavy', 'price' => '15', 'condition' => 'weight', 'min' => 1000, 'max' => null],
            ]],
            [...$world, 'rates' => [
                [...$world['rates'][0], 'condition' => 'none'],
                ['name' => 'Free over 200', 'price' => '0', 'condition' => 'subtotal', 'min' => '200', 'max' => null],
            ]],
        ]])->assertOk()->json();
    }

    public function test_new_store_has_a_worldwide_zone_and_variant_weight_is_saved(): void
    {
        $res = $this->as()->getJson('/api/admin/settings/shipping')->assertOk();
        $res->assertJsonCount(1, 'zones')->assertJsonPath('zones.0.countries', [])->assertJsonCount(3, 'zones.0.rates')
            ->assertJsonPath('zones.0.rates.1.condition', 'subtotal')->assertJsonPath('zones.0.rates.1.min', '99.00');

        $this->as()->getJson("/api/admin/products/{$this->productId}")->assertJsonPath('data.variants.0.weight', 400);
    }

    public function test_save_zones_keeps_ids_and_validates(): void
    {
        $before = $this->as()->getJson('/api/admin/settings/shipping')->json('zones.0');
        $saved = $this->setupZones();

        $this->assertSame('United States', $saved['zones'][0]['name']);
        $this->assertSame($before['id'], $saved['zones'][1]['id']);                     // zone kept
        $this->assertSame($before['rates'][0]['id'], $saved['zones'][1]['rates'][0]['id']);   // rate kept
        $this->assertSame(['weight', 0, 1000], [$saved['zones'][0]['rates'][0]['condition'], $saved['zones'][0]['rates'][0]['min'], $saved['zones'][0]['rates'][0]['max']]);
        $this->assertSame(4, ShippingRate::withoutGlobalScope('store')->count());          // dropped rates are deleted

        $zone = fn ($name, $countries) => ['name' => $name, 'countries' => $countries, 'rates' => []];
        $this->as()->putJson('/api/admin/settings/shipping', ['zones' => [$zone('A', ['US', 'CA']), $zone('B', ['CA'])]])
            ->assertStatus(422)->assertJsonPath('errors', ['zones.1.countries' => ['Canada同时出现在「A」和「B」两个区域']]);
        $this->as()->putJson('/api/admin/settings/shipping', ['zones' => [$zone('A', []), $zone('B', [])]])->assertStatus(422);
        $this->as()->putJson('/api/admin/settings/shipping', ['zones' => [['name' => 'A', 'countries' => ['XX'], 'rates' => []]]])->assertStatus(422);
        $this->as()->putJson('/api/admin/settings/shipping', ['zones' => [['name' => 'A', 'countries' => [], 'rates' => [
            ['name' => 'R', 'price' => 1, 'condition' => 'weight', 'min' => 500, 'max' => 100],
        ]]]])->assertStatus(422);
    }

    public function test_checkout_rates_follow_country_zone_weight_and_amount(): void
    {
        $this->setupZones();

        // 2 × 400 g = 800 g, $60
        $cart = $this->cart(2);
        $this->assertSame(['US Light'], array_column($this->rates($cart, 'US'), 'name'));
        $this->assertSame('2–4 days', $this->rates($cart, 'US')[0]['description']);
        $this->assertSame(['Standard Shipping'], array_column($this->rates($cart, 'GB'), 'name'));

        // 6 × 400 g = 2.4 kg, $180 → heavy rate in US; still no free shipping elsewhere (< $200)
        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/cart/add', ['variant_id' => $this->variantId, 'quantity' => 4]);
        $this->assertSame(['US Heavy'], array_column($this->rates($cart, 'US'), 'name'));
        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/cart/add', ['variant_id' => $this->variantId, 'quantity' => 1]);
        $this->assertSame(['Free over 200', 'Standard Shipping'], array_column($this->rates($cart, 'GB'), 'name'));

        // A rate from another zone can't be used for a US address
        $gbRate = $this->rates($cart, 'GB')[0]['id'];
        $order = [
            'email' => 'b@example.com', 'first_name' => 'A', 'last_name' => 'B', 'address1' => '1 St', 'city' => 'X', 'zip' => '1',
            'country' => 'US', 'payment_gateway' => 'test',
        ];
        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/checkout', $order + ['shipping_rate_id' => $gbRate])
            ->assertSessionHasErrors('shipping_rate_id');
        $usRate = $this->rates($cart, 'US')[0]['id'];
        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/checkout', $order + ['shipping_rate_id' => $usRate])->assertRedirect();
        $this->assertSame(1500, Order::withoutGlobalScope('store')->value('shipping_total'));

        // No "rest of world" zone → countries outside every zone get no rates
        ShippingZone::withoutGlobalScope('store')->whereNull('countries')->delete();
        $this->assertSame([], $this->rates($this->cart(1), 'GB'));
        $this->getJson(self::HOST.'/checkout/shipping-rates?country=ZZ')->assertStatus(422);
    }

    public function test_payment_settings_control_checkout(): void
    {
        $res = $this->as()->getJson('/api/admin/settings/payments')->assertOk();
        $res->assertJsonPath('settings.manual.enabled', true)->assertJsonPath('available.stripe', false)->assertJsonPath('available.test', true);

        // Validation: something must stay on; offline payment needs instructions
        $this->as()->putJson('/api/admin/settings/payments', ['stripe' => ['enabled' => true], 'manual' => ['enabled' => false], 'test' => ['enabled' => false]])
            ->assertStatus(422)->assertJsonValidationErrors('manual.enabled');   // Stripe isn't configured on the platform
        $this->as()->putJson('/api/admin/settings/payments', ['manual' => ['enabled' => true, 'instructions' => ' '], 'test' => ['enabled' => false]])
            ->assertStatus(422)->assertJsonValidationErrors('manual.instructions');

        $this->as()->putJson('/api/admin/settings/payments', [
            'stripe' => ['enabled' => true],
            'manual' => ['enabled' => true, 'label' => 'Wise transfer', 'instructions' => "Wise: shop@example.com\nReference: your order number"],
            'test' => ['enabled' => false],
        ])->assertOk()->assertJsonPath('settings.manual.label', 'Wise transfer');

        $cart = $this->cart(1);
        $this->withUnencryptedCookie('lumee_cart', $cart)->get(self::HOST.'/checkout')->assertOk()
            ->assertSee('Wise transfer')->assertSee('Wise: shop@example.com')
            ->assertDontSee('Test payment (dev only)');

        // The test gateway is switched off for this store → refused at checkout
        $rate = $this->rates($cart, 'US')[0]['id'];
        $order = ['email' => 'b@example.com', 'first_name' => 'A', 'last_name' => 'B', 'address1' => '1 St', 'city' => 'X', 'zip' => '1', 'country' => 'US', 'shipping_rate_id' => $rate];
        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/checkout', $order + ['payment_gateway' => 'test'])->assertSessionHasErrors('payment_gateway');

        // Manual payment: instructions on the order page and in the confirmation email
        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/checkout', $order + ['payment_gateway' => 'manual'])->assertRedirect();
        $placed = Order::withoutGlobalScope('store')->latest('id')->first();
        $this->get(self::HOST."/orders/{$placed->token}")->assertSee('Wise: shop@example.com')->assertSee('Wise transfer');
        Mail::assertSent(\App\Notifications\OrderMail::class, fn ($m) => $m->type === 'order_confirmation' && str_contains($m->render(), 'Wise: shop@example.com'));
    }

    public function test_staff_without_settings_permission_cannot_change_shipping_or_payments(): void
    {
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'S', 'email' => 's@example.com', 'password' => 'secret-pass', 'store_name' => 'Other']);
        \App\Models\Store::find($this->a['store_id'])->users()->attach(
            \App\Models\User::where('email', 's@example.com')->value('id'), ['role' => 'staff', 'permissions' => json_encode(['orders'])]);
        $this->app['auth']->forgetGuards();
        $staff = $this->withHeaders(['Authorization' => 'Bearer '.$res['token'], 'X-Store-Id' => (string) $this->a['store_id'], 'Accept' => 'application/json']);

        $staff->getJson('/api/admin/settings/shipping')->assertForbidden();
        $staff->putJson('/api/admin/settings/payments', ['manual' => ['enabled' => true]])->assertForbidden();
    }
}
