<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\ShippingRate;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private array $merchant;

    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.payments.test_gateway' => true, 'services.stripe.secret' => null]);

        $this->merchant = $this->register('a@example.com', 'Shop A');
        $this->variantId = $this->createProduct($this->merchant, 'Rose Quartz Ring', 40, 3);
    }

    private function register(string $email, string $store): array
    {
        $res = $this->postJson('/api/admin/auth/register', [
            'name' => 'M', 'email' => $email, 'password' => 'secret-pass', 'store_name' => $store,
        ])->assertCreated();

        return ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
    }

    private function admin(array $m): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$m['token'], 'X-Store-Id' => (string) $m['store_id']]);
    }

    private function createProduct(array $m, string $title, float $price, int $stock): int
    {
        $id = $this->admin($m)->postJson('/api/admin/products', [
            'title' => $title, 'price' => $price, 'inventory' => $stock, 'status' => 'active',
        ])->assertCreated()->json('data.id');

        return ProductVariant::withoutGlobalScope('store')->where('product_id', $id)->value('id');
    }

    /** Fresh storefront "browser": no auth headers, carries only the cart cookie. */
    private function shopper(?string $cartToken = null): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        return $cartToken ? $this->withUnencryptedCookie('lumee_cart', $cartToken) : $this;
    }

    private function addToCart(int $variantId, int $qty): string
    {
        $res = $this->shopper()->post(self::HOST.'/cart/add', ['variant_id' => $variantId, 'quantity' => $qty]);
        $res->assertRedirect('/cart');

        return $res->getCookie('lumee_cart', false)->getValue();
    }

    private function checkoutData(array $overrides = []): array
    {
        $rate = ShippingRate::withoutGlobalScope('store')->where('name', 'like', 'Standard%')->where('price', '>', 0)->first();

        return array_merge([
            'email' => 'Buyer@Example.com', 'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'address1' => '1 Main St', 'city' => 'Austin', 'province' => 'TX', 'zip' => '73301',
            'country' => 'US', 'shipping_rate_id' => $rate->id, 'payment_gateway' => 'test',
        ], $overrides);
    }

    public function test_full_checkout_creates_paid_order_and_decrements_stock(): void
    {
        $cart = $this->addToCart($this->variantId, 2);

        $this->shopper($cart)->get(self::HOST.'/cart')->assertOk()->assertSee('Rose Quartz Ring')->assertSee('$80.00');

        $res = $this->shopper($cart)->post(self::HOST.'/checkout', $this->checkoutData());
        $order = Order::withoutGlobalScope('store')->firstOrFail();
        $res->assertRedirect("/orders/{$order->token}");

        $this->assertSame(1001, $order->number);
        $this->assertSame(8000, $order->subtotal);
        $this->assertSame(990, $order->shipping_total);
        $this->assertSame(8990, $order->total);
        $this->assertSame('paid', $order->financial_status);
        $this->assertSame('buyer@example.com', $order->email);
        $this->assertSame(1, ProductVariant::withoutGlobalScope('store')->find($this->variantId)->inventory_quantity);
        $this->assertDatabaseHas('customers', ['email' => 'buyer@example.com', 'orders_count' => 1, 'total_spent' => 8990]);
        $this->assertDatabaseCount('carts', 0);

        $this->shopper()->get(self::HOST."/orders/{$order->token}")->assertOk()->assertSee('Thank you, Ada')->assertSee('#1001');
    }

    public function test_free_shipping_only_offered_over_threshold(): void
    {
        $free = ShippingRate::withoutGlobalScope('store')->where('price', 0)->first();

        // $40 subtotal is below the $99 threshold → free rate is rejected
        $cart = $this->addToCart($this->variantId, 1);
        $this->shopper($cart)->post(self::HOST.'/checkout', $this->checkoutData(['shipping_rate_id' => $free->id]))
            ->assertSessionHasErrors('shipping_rate_id');
        $this->assertDatabaseCount('orders', 0);

        // $120 subtotal → free shipping accepted
        $this->shopper($cart)->post(self::HOST.'/cart/items/'.CartItem::withoutGlobalScope('store')->value('id'), ['quantity' => 3]);
        $this->shopper($cart)->post(self::HOST.'/checkout', $this->checkoutData(['shipping_rate_id' => $free->id]));
        $this->assertSame(12000, Order::withoutGlobalScope('store')->value('total'));
    }

    public function test_cannot_add_or_buy_more_than_stock(): void
    {
        $this->shopper()->from(self::HOST.'/products/rose-quartz-ring')
            ->post(self::HOST.'/cart/add', ['variant_id' => $this->variantId, 'quantity' => 4])
            ->assertSessionHasErrors('quantity');

        // Stock drops after the item is in the bag (someone else bought it)
        $cart = $this->addToCart($this->variantId, 3);
        ProductVariant::withoutGlobalScope('store')->whereKey($this->variantId)->update(['inventory_quantity' => 1]);

        $this->shopper($cart)->post(self::HOST.'/checkout', $this->checkoutData())->assertSessionHasErrors('cart');
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(1, ProductVariant::withoutGlobalScope('store')->find($this->variantId)->inventory_quantity);
    }

    public function test_cannot_add_another_stores_variant_to_cart(): void
    {
        $b = $this->register('b@example.com', 'Shop B');
        $foreignVariant = $this->createProduct($b, 'B Necklace', 10, 5);

        $this->shopper()->post(self::HOST.'/cart/add', ['variant_id' => $foreignVariant, 'quantity' => 1])->assertNotFound();
    }

    public function test_order_numbers_are_per_store(): void
    {
        $b = $this->register('b@example.com', 'Shop B');
        $bVariant = $this->createProduct($b, 'B Necklace', 10, 5);

        $this->shopper($this->addToCart($this->variantId, 1))->post(self::HOST.'/checkout', $this->checkoutData());
        $this->shopper($this->addToCart($this->variantId, 1))->post(self::HOST.'/checkout', $this->checkoutData());

        $res = $this->shopper()->post('http://shop-b.lumee.localhost/cart/add', ['variant_id' => $bVariant, 'quantity' => 1]);
        $bRate = ShippingRate::withoutGlobalScope('store')->where('store_id', $b['store_id'])->where('price', '>', 0)->first();
        $this->shopper($res->getCookie('lumee_cart', false)->getValue())
            ->post('http://shop-b.lumee.localhost/checkout', $this->checkoutData(['shipping_rate_id' => $bRate->id]));

        $numbers = Order::withoutGlobalScope('store')->orderBy('id')->get(['store_id', 'number'])->map(fn ($o) => $o->number)->all();
        $this->assertSame([1001, 1002, 1001], $numbers);
    }

    public function test_admin_order_workflow_and_isolation(): void
    {
        $cart = $this->addToCart($this->variantId, 1);
        $this->shopper($cart)->post(self::HOST.'/checkout', $this->checkoutData(['payment_gateway' => 'manual']));
        $order = Order::withoutGlobalScope('store')->firstOrFail();
        $this->assertSame('pending', $order->financial_status);

        $this->admin($this->merchant)->getJson('/api/admin/orders')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', '#1001');
        $this->admin($this->merchant)->getJson('/api/admin/dashboard')->assertJsonPath('awaiting_payment', 1)->assertJsonPath('revenue', '0.00');

        // Another merchant can't see or act on it
        $b = $this->register('b@example.com', 'Shop B');
        $this->admin($b)->getJson('/api/admin/orders')->assertJsonCount(0, 'data');
        $this->admin($b)->getJson("/api/admin/orders/{$order->id}")->assertNotFound();
        $this->admin($b)->postJson("/api/admin/orders/{$order->id}/mark-paid")->assertNotFound();

        $this->admin($this->merchant)->postJson("/api/admin/orders/{$order->id}/mark-paid")->assertOk()->assertJsonPath('data.financial_status', 'paid');
        $this->admin($this->merchant)->postJson("/api/admin/orders/{$order->id}/fulfill", ['tracking_company' => 'DHL', 'tracking_number' => 'JD0001'])
            ->assertOk()->assertJsonPath('data.fulfillment_status', 'fulfilled');
        $this->admin($this->merchant)->getJson('/api/admin/dashboard')->assertJsonPath('revenue', '49.90')->assertJsonPath('to_fulfill', 0);
        $this->admin($this->merchant)->getJson('/api/admin/customers')->assertJsonPath('data.0.total_spent', '49.90');
    }

    public function test_stripe_checkout_redirects_and_webhook_marks_paid(): void
    {
        config(['services.stripe.secret' => 'sk_test_x', 'services.stripe.webhook_secret' => 'whsec_test']);
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_test_123', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_123'])]);

        $cart = $this->addToCart($this->variantId, 1);
        $this->shopper($cart)->post(self::HOST.'/checkout', $this->checkoutData(['payment_gateway' => 'stripe']))
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_123');

        $order = Order::withoutGlobalScope('store')->firstOrFail();
        $this->assertSame('pending', $order->financial_status);
        Http::assertSent(fn ($req) => $req['line_items[0][price_data][unit_amount]'] == 4000
            && $req['line_items[1][price_data][unit_amount]'] == 990
            && $req['metadata[order_id]'] == $order->id);

        $payload = json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => [
            'id' => 'cs_test_123', 'payment_status' => 'paid', 'payment_intent' => 'pi_123', 'metadata' => ['order_id' => $order->id],
        ]]]);
        $sign = fn ($secret) => 't='.time().',v1='.hash_hmac('sha256', time().'.'.$payload, $secret);

        // Bad signature is rejected and changes nothing
        $this->call('POST', '/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $sign('wrong'), 'CONTENT_TYPE' => 'application/json'], $payload)
            ->assertStatus(400);
        $this->assertSame('pending', $order->fresh()->financial_status);

        $this->call('POST', '/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $sign('whsec_test'), 'CONTENT_TYPE' => 'application/json'], $payload)
            ->assertOk();
        $this->assertSame('paid', $order->fresh()->financial_status);
        $this->assertSame('pi_123', $order->fresh()->payment_reference);
    }

    public function test_vouchers_are_paid_later_or_expire(): void
    {
        config(['services.stripe.secret' => 'sk_test_x', 'services.stripe.webhook_secret' => 'whsec_test']);
        Http::fake(['api.stripe.com/*' => Http::sequence()->push(['id' => 'cs_a', 'url' => 'https://checkout.stripe.com/a'])->push(['id' => 'cs_b', 'url' => 'https://checkout.stripe.com/b'])]);
        $send = function (string $type, string $session, Order $order, string $status) {
            $payload = json_encode(['type' => $type, 'data' => ['object' => ['id' => $session, 'payment_status' => $status, 'payment_intent' => 'pi_'.$session, 'metadata' => ['order_id' => $order->id]]]]);
            $t = time();

            return $this->call('POST', '/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => "t={$t},v1=".hash_hmac('sha256', $t.'.'.$payload, 'whsec_test'), 'CONTENT_TYPE' => 'application/json'], $payload)->assertOk();
        };
        $stock = fn () => ProductVariant::withoutGlobalScope('store')->find($this->variantId)->inventory_quantity;
        $before = $stock();

        // OXXO voucher: checkout completes unpaid, the buyer is told to pay it, then pays at the shop
        $this->shopper($this->addToCart($this->variantId, 1))->post(self::HOST.'/checkout', $this->checkoutData(['payment_gateway' => 'stripe']));
        $a = Order::withoutGlobalScope('store')->latest('id')->firstOrFail();
        $send('checkout.session.completed', 'cs_a', $a, 'unpaid');
        $this->assertSame('pending', $a->fresh()->financial_status);
        $this->get(self::HOST."/orders/{$a->token}")->assertSee('Waiting for your payment');
        $send('checkout.session.async_payment_succeeded', 'cs_a', $a, 'paid');
        $this->assertSame('paid', $a->fresh()->financial_status);

        // Boleto that expires unpaid: the order is cancelled and the stock comes back
        $this->shopper($this->addToCart($this->variantId, 1))->post(self::HOST.'/checkout', $this->checkoutData(['payment_gateway' => 'stripe']));
        $b = Order::withoutGlobalScope('store')->latest('id')->firstOrFail();
        $this->assertNotSame($a->id, $b->id);
        $send('checkout.session.completed', 'cs_b', $b, 'unpaid');
        $send('checkout.session.async_payment_failed', 'cs_b', $b, 'unpaid');
        $this->assertNotNull($b->fresh()->cancelled_at);
        $this->assertSame('voided', $b->fresh()->financial_status);
        $this->assertSame($before - 1, $stock());   // only the paid order keeps its unit

        // A forged session id changes nothing
        $send('checkout.session.async_payment_succeeded', 'cs_other', $b, 'paid');
        $this->assertSame('voided', $b->fresh()->financial_status);
    }
}
