<?php

namespace Tests\Feature;

use App\Models\Discount;
use App\Models\Order;
use App\Models\Promotion;
use App\Models\ShippingRate;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PromotionTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private array $a;

    private array $ring;      // $50

    private array $scarf;     // $30

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.payments.test_gateway' => true]);
        Mail::fake();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $res = $this->postJson('/api/admin/auth/register', [
            'name' => 'M', 'email' => 'owner@shop-a.test', 'password' => 'secret-pass', 'store_name' => 'Shop A',
        ])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
        $this->ring = $this->as()->postJson('/api/admin/products', ['title' => 'Gold Ring', 'price' => 50, 'inventory' => 50, 'status' => 'active'])->json('data');
        $this->scarf = $this->as()->postJson('/api/admin/products', ['title' => 'Silk Scarf', 'price' => 30, 'inventory' => 50, 'status' => 'active'])->json('data');
    }

    private function as(?array $a = null): static
    {
        $a ??= $this->a;
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$a['token'], 'X-Store-Id' => (string) $a['store_id'], 'Accept' => 'application/json']);
    }

    private function promo(array $data): array
    {
        return $this->as()->postJson('/api/admin/promotions', [
            'value_type' => 'percentage', 'combines_with_codes' => true, 'enabled' => true, ...$data,
        ])->assertCreated()->json('data');
    }

    /** @param array<int, array{0:array,1:int}> $items [product, qty] */
    private function cart(array $items): string
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->defaultCookies = $this->unencryptedCookies = [];   // a fresh shopper each time
        $cart = null;
        foreach ($items as [$product, $qty]) {
            $req = $cart ? $this->withUnencryptedCookie('lumee_cart', $cart) : $this;
            $cart = $req->post(self::HOST.'/cart/add', ['variant_id' => $product['variants'][0]['id'], 'quantity' => $qty])->getCookie('lumee_cart', false)?->getValue() ?? $cart;
        }

        return $cart;
    }

    private function checkout(string $cart): Order
    {
        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/checkout', [
            'email' => 'b@example.com', 'first_name' => 'A', 'last_name' => 'B', 'address1' => '1 St', 'city' => 'X', 'zip' => '1', 'country' => 'US',
            'shipping_rate_id' => ShippingRate::withoutGlobalScope('store')->where('price', '>', 0)->value('id'), 'payment_gateway' => 'test',
        ])->assertRedirect()->assertSessionHasNoErrors();

        return Order::withoutGlobalScope('store')->with('items')->latest('id')->firstOrFail();
    }

    public function test_timed_sale_lowers_prices_on_the_storefront_only(): void
    {
        $this->promo(['type' => 'sale', 'title' => 'Summer Sale', 'value' => 20, 'applies_to' => 'products', 'product_ids' => [$this->ring['id']],
            'countdown' => true, 'ends_at' => now()->addDays(2)->toIso8601String()]);

        $page = $this->get(self::HOST.'/products/gold-ring')->assertOk()
            ->assertSee('$40.00')->assertSee('$50.00')->assertSee('Save 20%')->assertSee('Summer Sale')->assertSee('data-ends=', false);
        $this->get(self::HOST.'/products/silk-scarf')->assertOk()->assertDontSee('Summer Sale');
        $this->get(self::HOST.'/collections/all')->assertOk()->assertSee('$40.00')->assertSee('Sale');

        $order = $this->checkout($this->cart([[$this->ring, 2]]));
        $this->assertSame([4000, 8000, 0], [$order->items[0]->price, $order->subtotal, $order->discount_total]);

        // Admin and stock math see the real price
        $this->as()->getJson("/api/admin/products/{$this->ring['id']}")->assertJsonPath('data.variants.0.price', '50.00');

        // Scheduled and ended sales don't apply
        Promotion::withoutGlobalScope('store')->update(['starts_at' => now()->addDay()]);
        $this->flushHeaders();
        $this->get(self::HOST.'/products/gold-ring')->assertDontSee('$40.00');
        Promotion::withoutGlobalScope('store')->update(['starts_at' => null, 'ends_at' => now()->subMinute()]);
        $this->get(self::HOST.'/products/gold-ring')->assertDontSee('$40.00');
    }

    public function test_sale_by_collection_and_the_best_sale_wins(): void
    {
        $col = $this->as()->postJson('/api/admin/collections', ['title' => 'Scarves', 'product_ids' => [$this->scarf['id']], 'published' => true])->assertCreated()->json('data.id');
        $this->promo(['type' => 'sale', 'title' => 'Scarf week', 'value_type' => 'fixed', 'value' => 5, 'applies_to' => 'collections', 'collection_ids' => [$col]]);
        $this->promo(['type' => 'sale', 'title' => 'Storewide', 'value' => 10, 'applies_to' => 'all']);

        $this->flushHeaders();
        $this->get(self::HOST.'/products/silk-scarf')->assertSee('$25.00')->assertSee('Scarf week');   // −$5 beats −10% ($3)
        $this->get(self::HOST.'/products/gold-ring')->assertSee('$45.00')->assertSee('Storewide');
    }

    public function test_spend_more_tiers_with_nudge_stats_and_order_lines(): void
    {
        $p = $this->promo(['type' => 'tiered', 'title' => 'Spend & save', 'value_type' => 'fixed', 'tiers' => [['min' => 200, 'value' => 30], ['min' => 100, 'value' => 10]]]);
        $this->assertSame(['100.00', '200.00'], array_column($p['tiers'], 'min'));   // stored ascending

        $cart = $this->cart([[$this->scarf, 3]]);   // $90
        $this->withUnencryptedCookie('lumee_cart', $cart)->get(self::HOST.'/cart')->assertSee('Spend $10.00 more to save $10.00');

        $cart = $this->cart([[$this->ring, 1], [$this->scarf, 3]]);   // $140 → $10 off, nudge to the $200 tier
        $this->withUnencryptedCookie('lumee_cart', $cart)->get(self::HOST.'/cart')
            ->assertSee('Spend &amp; save', false)->assertSee('−$10.00', false)->assertSee('Spend $60.00 more to save $30.00');
        $this->withUnencryptedCookie('lumee_cart', $cart)->get(self::HOST.'/checkout')->assertSee('Spend &amp; save', false)->assertSee('−$10.00', false);

        $order = $this->checkout($cart);
        $this->assertSame([14000, 1000], [$order->subtotal, $order->discount_total]);
        $this->assertSame(14000 - 1000 + $order->shipping_total, $order->total);
        $this->assertSame([['type' => 'promotion', 'id' => $p['id'], 'title' => 'Spend & save', 'amount' => 1000]], $order->discount_lines);
        $this->as()->getJson("/api/admin/promotions/{$p['id']}")->assertJsonPath('data.orders_count', 1)->assertJsonPath('data.discount_total', '10.00');
        $this->as()->getJson("/api/admin/orders/{$order->id}")->assertJsonPath('data.discount_lines.0.amount', '10.00');
    }

    public function test_bundle_discount_per_complete_set(): void
    {
        $this->promo(['type' => 'bundle', 'title' => 'Ring + Scarf', 'value' => 10, 'product_ids' => [$this->ring['id'], $this->scarf['id']]]);

        $this->assertSame(0, $this->checkout($this->cart([[$this->ring, 2]]))->discount_total);   // no complete set
        $order = $this->checkout($this->cart([[$this->ring, 2], [$this->scarf, 1]]));            // one set of $80
        $this->assertSame(800, $order->discount_total);
        $order = $this->checkout($this->cart([[$this->ring, 2], [$this->scarf, 2]]));            // two sets
        $this->assertSame(1600, $order->discount_total);
    }

    public function test_discount_codes_stack_or_the_better_deal_wins(): void
    {
        $this->as()->postJson('/api/admin/discounts', ['code' => 'TAKE5', 'type' => 'fixed', 'value' => 5])->assertCreated();
        $this->as()->postJson('/api/admin/discounts', ['code' => 'BIG50', 'type' => 'percentage', 'value' => 50])->assertCreated();
        $p = $this->promo(['type' => 'tiered', 'title' => 'Tier', 'tiers' => [['min' => 50, 'value' => 10]]]);   // 10% off ≥ $50

        // Combinable: 10% of $100 = $10, then the code's $5 on what's left
        $cart = $this->cart([[$this->ring, 2]]);
        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/cart/discount', ['code' => 'TAKE5'])->assertSessionHas('status', 'Discount TAKE5 applied.');
        $order = $this->checkout($cart);
        $this->assertSame(1500, $order->discount_total);
        $this->assertSame(['promotion', 'code'], array_column($order->discount_lines, 'type'));
        $this->assertSame('TAKE5', $order->discount_code);

        // Not combinable: $5 code < $10 promotion → promotion only, code isn't used up
        $this->as()->putJson("/api/admin/promotions/{$p['id']}", ['title' => 'Tier', 'value_type' => 'percentage', 'enabled' => true, 'combines_with_codes' => false, 'tiers' => [['min' => 50, 'value' => 10]]])->assertOk();
        $cart = $this->cart([[$this->ring, 2]]);
        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/cart/discount', ['code' => 'TAKE5'])
            ->assertSessionHas('status', 'Code TAKE5 can’t be combined with the current promotion, which saves you more.');
        $order = $this->checkout($cart);
        $this->assertSame([1000, null], [$order->discount_total, $order->discount_code]);
        $this->assertSame(1, Discount::withoutGlobalScope('store')->where('code', 'TAKE5')->value('usage_count'));

        // Not combinable: 50% code > 10% promotion → code only
        $cart = $this->cart([[$this->ring, 2]]);
        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/cart/discount', ['code' => 'BIG50']);
        $order = $this->checkout($cart);
        $this->assertSame([5000, 'BIG50'], [$order->discount_total, $order->discount_code]);
        $this->assertSame(['code'], array_column($order->discount_lines, 'type'));
    }

    public function test_validation_and_store_isolation(): void
    {
        $base = ['value_type' => 'percentage', 'combines_with_codes' => true, 'enabled' => true];
        $post = fn (array $d) => $this->as()->postJson('/api/admin/promotions', [...$base, ...$d]);

        $post(['type' => 'sale', 'title' => 'X', 'value' => 100, 'applies_to' => 'all'])->assertStatus(422)->assertJsonValidationErrors('value');
        $post(['type' => 'sale', 'title' => 'X', 'value' => 10, 'applies_to' => 'products', 'product_ids' => []])->assertStatus(422)->assertJsonValidationErrors('product_ids');
        $post(['type' => 'tiered', 'title' => 'X', 'tiers' => [['min' => 100, 'value' => 20], ['min' => 200, 'value' => 10]]])->assertStatus(422)->assertJsonValidationErrors('tiers.1.value');
        $post(['type' => 'tiered', 'title' => 'X', 'value_type' => 'fixed', 'tiers' => [['min' => 10, 'value' => 10]]])->assertStatus(422)->assertJsonValidationErrors('tiers.0.value');
        $post(['type' => 'bundle', 'title' => 'X', 'value' => 10, 'product_ids' => [$this->ring['id']]])->assertStatus(422)->assertJsonValidationErrors('product_ids');
        $post(['type' => 'sale', 'title' => 'X', 'value' => 10, 'applies_to' => 'all', 'starts_at' => '2026-12-02', 'ends_at' => '2026-12-01'])->assertStatus(422)->assertJsonValidationErrors('ends_at');

        // Another store's products are dropped from a bundle
        $b = $this->postJson('/api/admin/auth/register', ['name' => 'B', 'email' => 'b@shop-b.test', 'password' => 'secret-pass', 'store_name' => 'Shop B'])->json();
        $other = ['token' => $b['token'], 'store_id' => $b['stores'][0]['id']];
        $foreign = $this->as($other)->postJson('/api/admin/products', ['title' => 'Theirs', 'price' => 10, 'status' => 'active'])->json('data.id');
        $this->as()->postJson('/api/admin/promotions', [...$base, 'type' => 'bundle', 'title' => 'X', 'value' => 10, 'product_ids' => [$this->ring['id'], $foreign]])
            ->assertStatus(422)->assertJsonValidationErrors('product_ids');

        $p = $this->promo(['type' => 'sale', 'title' => 'Mine', 'value' => 50, 'applies_to' => 'all']);
        $this->as($other)->getJson("/api/admin/promotions/{$p['id']}")->assertNotFound();
        $this->flushHeaders();
        $this->get('http://shop-b.lumee.localhost/products/theirs')->assertOk()->assertSee('$10.00')->assertDontSee('$5.00');   // Shop A's sale doesn't leak
        $this->as()->putJson("/api/admin/promotions/{$p['id']}", [...$p, 'type' => 'tiered'])->assertStatus(422);   // type is fixed
    }
}
