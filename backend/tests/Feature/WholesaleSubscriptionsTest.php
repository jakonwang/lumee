<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerSubscription;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\ShippingRate;
use App\Notifications\SubscriptionMail;
use App\Subscriptions\Subscriptions;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WholesaleSubscriptionsTest extends TestCase
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
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => 'a@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop A'])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
        $this->productId = $this->as()->postJson('/api/admin/products', ['title' => 'Coffee Beans', 'price' => 20, 'inventory' => 100, 'status' => 'active'])->json('data.id');
        $this->variantId = ProductVariant::withoutGlobalScope('store')->where('product_id', $this->productId)->value('id');
    }

    private function as(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->a['token'], 'X-Store-Id' => (string) $this->a['store_id']]);
    }

    private function shopper(): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        return $this;
    }

    private function checkout(string $cart, string $gateway = 'test')
    {
        $rate = ShippingRate::withoutGlobalScope('store')->where('store_id', $this->a['store_id'])->where('price', 990)->first();

        return $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/checkout', [
            'email' => 'buyer@example.com', 'first_name' => 'Ada', 'last_name' => 'Lee', 'address1' => '1 St', 'city' => 'Austin',
            'zip' => '78701', 'country' => 'US', 'shipping_rate_id' => $rate->id, 'payment_gateway' => $gateway,
        ]);
    }

    public function test_wholesale_price_list_for_tagged_customers(): void
    {
        $other = $this->as()->postJson('/api/admin/products', ['title' => 'Mug', 'price' => 12, 'inventory' => 50, 'status' => 'active'])->json('data.id');
        $mugVariant = ProductVariant::withoutGlobalScope('store')->where('product_id', $other)->value('id');
        $list = $this->as()->postJson('/api/admin/price-lists', [
            'name' => 'Wholesale', 'customer_tag' => 'Wholesale', 'percent_off' => 25, 'min_subtotal' => 50,
            'prices' => [['variant_id' => $mugVariant, 'price' => 6]],
        ])->assertCreated()->json('data');
        $this->as()->getJson("/api/admin/price-lists/{$list['id']}")->assertJsonPath('data.prices.0.price', '6.00')->assertJsonPath('data.prices.0.regular', '12.00');

        // Guests see normal prices
        $this->shopper()->get(self::HOST.'/products/coffee-beans')->assertSee('$20.00')->assertDontSee('$15.00');

        // A customer tagged "wholesale" (any case) sees list prices everywhere
        $this->post(self::HOST.'/account/register', ['first_name' => 'B', 'last_name' => 'Buyer', 'email' => 'buyer@example.com', 'password' => 'correct-horse', 'password_confirmation' => 'correct-horse']);
        $c = Customer::withoutGlobalScope('store')->where('email', 'buyer@example.com')->first();
        $this->as()->patchJson("/api/admin/customers/{$c->id}", ['tags' => ['wholesale']])->assertOk();
        $this->as()->getJson('/api/admin/price-lists')->assertJsonPath('data.0.customers', 1);

        $this->shopper()->post(self::HOST.'/account/login', ['email' => 'buyer@example.com', 'password' => 'correct-horse']);
        $this->get(self::HOST.'/products/coffee-beans')->assertSee('$15.00');
        $cart = $this->post(self::HOST.'/cart/add', ['variant_id' => $this->variantId, 'quantity' => 1])->getCookie('lumee_cart', false)->getValue();
        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/cart/add', ['variant_id' => $mugVariant, 'quantity' => 2]);
        $this->withUnencryptedCookie('lumee_cart', $cart)->get(self::HOST.'/cart')->assertSee('Wholesale prices applied');

        // 15 + 2×6 = 27 < 50 minimum
        $this->checkout($cart)->assertRedirect('/cart')->assertSessionHasErrors('cart');
        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/cart/add', ['variant_id' => $this->variantId, 'quantity' => 2]);
        $this->checkout($cart)->assertRedirect();
        $order = Order::withoutGlobalScope('store')->latest('id')->first();
        $this->assertSame(1500 * 3 + 600 * 2, (int) $order->subtotal);
        $this->assertSame([1500, 600], $order->items->sortBy('id')->pluck('price')->map(fn ($p) => (int) $p)->values()->all());

        // Disabled list → regular prices again
        $this->as()->putJson("/api/admin/price-lists/{$list['id']}", ['name' => 'Wholesale', 'customer_tag' => 'wholesale', 'percent_off' => 25, 'enabled' => false])->assertOk();
        $this->shopper()->post(self::HOST.'/account/login', ['email' => 'buyer@example.com', 'password' => 'correct-horse']);
        $this->get(self::HOST.'/products/coffee-beans')->assertSee('$20.00');
    }

    public function test_subscribe_and_save_lifecycle(): void
    {
        $detail = $this->as()->getJson("/api/admin/products/{$this->productId}")->json('data');
        $this->as()->putJson("/api/admin/products/{$this->productId}", [...$detail, 'subscription' => ['enabled' => true, 'discount' => 10, 'intervals' => ['bogus']]])
            ->assertJsonValidationErrors('subscription.intervals.0');
        $this->as()->putJson("/api/admin/products/{$this->productId}", [...$detail, 'subscription' => ['enabled' => true, 'discount' => 10, 'intervals' => ['month:1', 'week:2'], 'only' => false]])
            ->assertOk()->assertJsonPath('data.subscription.intervals', ['week:2', 'month:1']);

        $this->shopper()->get(self::HOST.'/products/coffee-beans')->assertSee('Save 10%')->assertSee('value="month:1"', false);
        $this->post(self::HOST.'/cart/add', ['variant_id' => $this->variantId, 'quantity' => 1, 'plan' => 'month:6'])->assertSessionHasErrors('plan');
        $cart = $this->post(self::HOST.'/cart/add', ['variant_id' => $this->variantId, 'quantity' => 2, 'plan' => 'month:1'])->getCookie('lumee_cart', false)->getValue();
        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/cart/add', ['variant_id' => $this->variantId, 'quantity' => 1]);   // same coffee, one-time
        $this->withUnencryptedCookie('lumee_cart', $cart)->get(self::HOST.'/cart')->assertSee('Delivered every month')->assertSee('$18.00');
        // Subscription bags can't use payment methods that can't charge again
        $this->withUnencryptedCookie('lumee_cart', $cart)->get(self::HOST.'/checkout')->assertOk();

        Carbon::setTestNow('2026-03-10 12:00:00');
        $this->checkout($cart)->assertRedirect();
        $order = Order::withoutGlobalScope('store')->latest('id')->first();
        $this->assertSame(1800 * 2 + 2000, (int) $order->subtotal);
        $this->assertSame('month:1', $order->items->firstWhere('plan', 'month:1')->plan);
        $sub = CustomerSubscription::withoutGlobalScope('store')->first();
        $this->assertNotNull($sub);
        $this->assertSame('active', $sub->status);
        $this->assertSame(2, $sub->items[0]['quantity']);
        $this->assertSame('2026-04-10', $sub->next_billing_at->toDateString());
        Mail::assertSent(SubscriptionMail::class, fn ($m) => $m->type === 'started');
        $this->get(self::HOST."/orders/{$order->token}")->assertSee('Manage subscription');

        // Not due yet → nothing happens; then due → renewal order at the subscription price, paid, stock reserved
        $this->assertSame(0, app(Subscriptions::class)->renewDue());
        Carbon::setTestNow('2026-04-10 13:00:00');
        $stock = (int) ProductVariant::withoutGlobalScope('store')->find($this->variantId)->inventory_quantity;
        $this->assertSame(1, app(Subscriptions::class)->renewDue());
        $renewal = Order::withoutGlobalScope('store')->latest('id')->first();
        $this->assertSame($sub->id, $renewal->subscription_id);
        $this->assertSame('subscription', $renewal->source);
        $this->assertSame('paid', $renewal->financial_status);
        $this->assertSame(3600, (int) $renewal->subtotal);
        $this->assertSame(3600 + 990, (int) $renewal->total);
        $this->assertSame($stock - 2, (int) ProductVariant::withoutGlobalScope('store')->find($this->variantId)->inventory_quantity);
        $sub->refresh();
        $this->assertSame('2026-05-10', $sub->next_billing_at->toDateString());
        $this->assertSame(2, $sub->orders_count);
        $this->assertSame(1, CustomerSubscription::withoutGlobalScope('store')->count());   // a renewal doesn't start another subscription

        // Buyer page: skip, pause, resume, cancel
        $page = self::HOST."/subscriptions/{$sub->token}";
        $this->get($page)->assertOk()->assertSee('Next delivery');
        $this->from($page)->post($page, ['action' => 'skip'])->assertSessionHas('status');
        $this->assertSame('2026-06-10', $sub->fresh()->next_billing_at->toDateString());
        $this->post($page, ['action' => 'pause']);
        $this->assertSame('paused', $sub->fresh()->status);
        Carbon::setTestNow('2026-07-01 00:00:00');
        $this->assertSame(0, app(Subscriptions::class)->renewDue());   // paused: no charge
        $this->post($page, ['action' => 'resume']);
        $this->assertSame('active', $sub->fresh()->status);

        // Admin: list, value, cancel
        $this->as()->getJson('/api/admin/subscriptions')->assertJsonPath('counts.active', 1)->assertJsonPath('data.0.value', '36.00')->assertJsonPath('monthly_value', '36.00');
        $this->as()->postJson("/api/admin/subscriptions/{$sub->id}", ['action' => 'cancel'])->assertJsonPath('data.status', 'cancelled');
        Mail::assertSent(SubscriptionMail::class, fn ($m) => $m->type === 'cancelled');
        $this->as()->postJson("/api/admin/subscriptions/{$sub->id}", ['action' => 'resume'])->assertStatus(422);
        Carbon::setTestNow();
    }

    public function test_failed_renewal_retries_then_cancels(): void
    {
        Http::fake([
            'api.stripe.com/v1/payment_intents' => Http::response(['error' => ['message' => 'Your card was declined.']], 402),
            'api.stripe.com/v1/payment_intents/*' => Http::response(['customer' => 'cus_1', 'payment_method' => 'pm_1']),
        ]);
        $sub = CustomerSubscription::withoutGlobalScope('store')->forceCreate([
            'store_id' => $this->a['store_id'], 'token' => (string) \Illuminate\Support\Str::uuid(), 'email' => 'buyer@example.com', 'status' => 'active',
            'interval' => 'month:1', 'discount' => 10, 'items' => [['variant_id' => $this->variantId, 'product_id' => $this->productId, 'quantity' => 1, 'title' => 'Coffee Beans', 'variant_title' => null]],
            'shipping_address' => ['first_name' => 'A', 'country' => 'US', 'city' => 'X'], 'shipping_method' => ['name' => 'Standard', 'price' => 990],
            'currency' => 'USD', 'payment_gateway' => 'stripe', 'stripe_customer' => 'cus_1', 'stripe_payment_method' => 'pm_1', 'next_billing_at' => now()->subMinute(),
        ]);
        $orders = Order::withoutGlobalScope('store')->count();

        app(Subscriptions::class)->renewDue();
        $sub->refresh();
        $this->assertSame('past_due', $sub->status);
        $this->assertSame(1, $sub->failed_attempts);
        $this->assertSame('Your card was declined.', $sub->last_error);
        $this->assertTrue($sub->next_billing_at->isFuture());
        $this->assertSame($orders, Order::withoutGlobalScope('store')->count());   // no order left behind
        Mail::assertSent(SubscriptionMail::class, fn ($m) => $m->type === 'renewal_failed');

        foreach ([2, 3] as $n) {
            $sub->refresh()->forceFill(['next_billing_at' => now()->subMinute()])->save();
            app(Subscriptions::class)->renewDue();
        }
        $this->assertSame('cancelled', $sub->fresh()->status, json_encode($sub->fresh()->only('failed_attempts', 'last_error', 'next_billing_at')));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/payment_intents') && $r['off_session'] === 'true' && $r['amount'] == 1800 + 990);
    }
}
