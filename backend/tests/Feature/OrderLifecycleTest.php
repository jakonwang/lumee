<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\ShippingRate;
use App\Notifications\OrderMail;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private array $a;

    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.payments.test_gateway' => true]);
        Mail::fake();

        $this->a = $this->register('owner@shop-a.test', 'Shop A');
        $id = $this->as($this->a)->postJson('/api/admin/products', [
            'title' => 'Rose Quartz Ring', 'price' => 40, 'inventory' => 10, 'status' => 'active',
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

        return $this->withHeaders(['Authorization' => 'Bearer '.$m['token'], 'X-Store-Id' => (string) $m['store_id'], 'Accept' => 'application/json']);
    }

    private function checkout(string $gateway, int $qty = 2): Order
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $res = $this->post(self::HOST.'/cart/add', ['variant_id' => $this->variantId, 'quantity' => $qty]);
        $cart = $res->getCookie('lumee_cart', false)->getValue();
        $rate = ShippingRate::withoutGlobalScope('store')->where('store_id', $this->a['store_id'])->where('price', '>', 0)->first();

        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/checkout', [
            'email' => 'buyer@example.com', 'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'address1' => '1 Main St', 'city' => 'Austin', 'zip' => '73301', 'country' => 'US',
            'shipping_rate_id' => $rate->id, 'payment_gateway' => $gateway,
        ])->assertRedirect();

        return Order::withoutGlobalScope('store')->latest('id')->firstOrFail();
    }

    private function stock(): int
    {
        return ProductVariant::withoutGlobalScope('store')->whereKey($this->variantId)->value('inventory_quantity');
    }

    private function item(Order $order): int
    {
        return $order->items()->withoutGlobalScope('store')->value('id');
    }

    public function test_partial_then_full_refund_with_restock_and_net_sales(): void
    {
        $order = $this->checkout('test');                         // 2 × $40 + shipping, paid instantly
        $this->assertSame('paid', $order->financial_status);
        $this->assertSame(8, $this->stock());
        $shipping = $order->shipping_total;

        // Refund one ring and put it back in stock
        $res = $this->as($this->a)->postJson("/api/admin/orders/{$order->id}/refunds", [
            'items' => [['id' => $this->item($order), 'quantity' => 1]], 'amount' => '40.00', 'reason' => 'Wrong size', 'restock' => true,
        ])->assertOk();
        $res->assertJsonPath('data.financial_status', 'partially_refunded')
            ->assertJsonPath('data.refunded_total', '40.00')
            ->assertJsonPath('data.items.0.refunded_quantity', 1)
            ->assertJsonPath('data.refund_info.refunds_online', false)
            ->assertJsonPath('data.refunds.0.reason', 'Wrong size');
        $this->assertSame(9, $this->stock());
        $this->assertStringContainsString('需线下退款', $res->json('data.events.0.message'));
        $this->assertSame(8000 + $shipping - 4000, Customer::withoutGlobalScope('store')->value('total_spent'));

        // Guards: more items than left, more money than left, nothing at all
        $this->as($this->a)->postJson("/api/admin/orders/{$order->id}/refunds", [
            'items' => [['id' => $this->item($order), 'quantity' => 2]], 'amount' => '1',
        ])->assertStatus(422)->assertJsonPath('errors.items.0', '「Rose Quartz Ring」最多还能退 1 件');
        $this->as($this->a)->postJson("/api/admin/orders/{$order->id}/refunds", ['amount' => '9999'])
            ->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->as($this->a)->postJson("/api/admin/orders/{$order->id}/refunds", ['amount' => '0'])->assertStatus(422);

        // Refund the rest including shipping, no restock
        $rest = number_format(($order->total - 4000) / 100, 2, '.', '');
        $this->as($this->a)->postJson("/api/admin/orders/{$order->id}/refunds", [
            'items' => [['id' => $this->item($order), 'quantity' => 1]], 'amount' => $rest,
            'shipping_amount' => number_format($shipping / 100, 2, '.', ''), 'notify' => true,
        ])->assertOk()->assertJsonPath('data.financial_status', 'refunded')->assertJsonPath('data.refundable', '0.00');
        $this->assertSame(9, $this->stock());
        $this->assertSame(2, Refund::withoutGlobalScope('store')->count());
        Mail::assertSent(OrderMail::class, fn ($m) => $m->type === 'refund_notification' && str_contains($m->render(), 'Refunded'));

        // Fully refunded → net sales 0
        $this->as($this->a)->getJson('/api/admin/dashboard')->assertJsonPath('revenue', '0.00');
        $this->as($this->a)->getJson('/api/admin/analytics?days=7')->assertJsonPath('totals.revenue.value', '0.00');
    }

    public function test_stripe_refund_goes_through_the_api_and_failures_change_nothing(): void
    {
        config(['services.stripe.secret' => 'sk_test_x']);
        $order = $this->checkout('manual');
        $order->forceFill(['payment_gateway' => 'stripe', 'payment_reference' => 'pi_123', 'financial_status' => 'paid', 'paid_at' => now()])->save();

        Http::fake(['api.stripe.com/v1/refunds' => Http::sequence()
            ->push(['error' => ['message' => 'Charge already refunded']], 400)
            ->push(['id' => 're_abc'], 200)]);

        $this->as($this->a)->postJson("/api/admin/orders/{$order->id}/refunds", ['amount' => '10'])
            ->assertStatus(422)->assertJsonPath('errors.amount.0', 'Stripe 退款失败：Charge already refunded');
        $this->assertSame(0, Refund::withoutGlobalScope('store')->count());
        $this->assertSame(0, $order->fresh()->refunded_total);

        $this->as($this->a)->postJson("/api/admin/orders/{$order->id}/refunds", ['amount' => '10'])
            ->assertOk()->assertJsonPath('data.refunds.0.reference', 're_abc')
            ->assertJsonPath('data.refund_info.refunds_online', true);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.stripe.com/v1/refunds'
            && $r['payment_intent'] === 'pi_123' && (int) $r['amount'] === 1000 && $r->hasHeader('Idempotency-Key'));
    }

    public function test_cancel_unpaid_order_voids_it_restocks_and_blocks_further_actions(): void
    {
        $order = $this->checkout('manual');
        $this->assertSame(8, $this->stock());

        $this->as($this->a)->postJson("/api/admin/orders/{$order->id}/cancel", ['reason' => 'nope'])->assertStatus(422);
        $res = $this->as($this->a)->postJson("/api/admin/orders/{$order->id}/cancel", [
            'reason' => 'inventory', 'restock' => true, 'notify' => true,
        ])->assertOk();
        $res->assertJsonPath('data.financial_status', 'voided')->assertJsonPath('data.cancel_reason_label', '库存不足');
        $this->assertNotNull($res->json('data.cancelled_at'));
        $this->assertSame(10, $this->stock());
        Mail::assertSent(OrderMail::class, fn ($m) => $m->type === 'order_cancelled' && str_contains($m->render(), 'no longer available'));

        $this->as($this->a)->postJson("/api/admin/orders/{$order->id}/cancel", ['reason' => 'other'])->assertStatus(422);
        $this->as($this->a)->postJson("/api/admin/orders/{$order->id}/mark-paid")->assertStatus(422);
        $this->as($this->a)->postJson("/api/admin/orders/{$order->id}/fulfill")->assertStatus(422);

        // Buyer pays anyway (e.g. old Stripe tab): money is recorded, order stays cancelled, merchant can refund
        $order->fresh()->markPaid('pi_late');
        $o = $order->fresh();
        $this->assertSame('paid', $o->financial_status);
        $this->assertTrue($o->isCancelled());
        $this->assertSame($o->total, $o->refundable());

        // Storefront status page says cancelled; orders list "open" tab hides it
        $this->get(self::HOST."/orders/{$order->token}")->assertOk()->assertSee('This order has been cancelled');
        $this->as($this->a)->getJson('/api/admin/orders?status=open')->assertJsonCount(0, 'data');
        $this->as($this->a)->getJson('/api/admin/orders?status=cancelled')->assertJsonCount(1, 'data');
    }

    public function test_cancel_paid_order_refunds_everything_and_restocks_once(): void
    {
        $order = $this->checkout('test');
        $res = $this->as($this->a)->postJson("/api/admin/orders/{$order->id}/cancel", ['reason' => 'customer', 'restock' => true, 'refund' => true])->assertOk();

        $res->assertJsonPath('data.financial_status', 'refunded')
            ->assertJsonPath('data.refunded_total', number_format($order->total / 100, 2, '.', ''))
            ->assertJsonPath('data.items.0.refunded_quantity', 2);
        $this->assertSame(10, $this->stock());                    // put back once, not twice
        $this->assertSame(['cancelled', 'refunded', 'paid', 'placed'], collect($res->json('data.events'))->pluck('type')->all());
        $this->as($this->a)->postJson("/api/admin/orders/{$order->id}/refunds", ['amount' => '1'])->assertStatus(422);
    }

    public function test_cancel_paid_order_without_refund_then_refund_later_does_not_restock_twice(): void
    {
        $order = $this->checkout('test');
        $this->as($this->a)->postJson("/api/admin/orders/{$order->id}/cancel", ['reason' => 'fraud', 'restock' => true, 'refund' => false])
            ->assertOk()->assertJsonPath('data.financial_status', 'paid');
        $this->assertSame(10, $this->stock());

        $this->as($this->a)->postJson("/api/admin/orders/{$order->id}/refunds", [
            'items' => [['id' => $this->item($order), 'quantity' => 2]], 'amount' => '80', 'restock' => true,
        ])->assertOk()->assertJsonPath('data.financial_status', 'partially_refunded');
        $this->assertSame(10, $this->stock());
    }

    public function test_fulfilled_orders_cannot_be_cancelled_tracking_and_comments(): void
    {
        $order = $this->checkout('test');
        $this->as($this->a)->postJson("/api/admin/orders/{$order->id}/fulfill", ['tracking_company' => 'DHL', 'tracking_number' => 'X1'])->assertOk();
        $this->as($this->a)->postJson("/api/admin/orders/{$order->id}/cancel", ['reason' => 'customer'])
            ->assertStatus(422)->assertJsonPath('errors.order.0', '已发货的订单不能取消，请使用退款');

        $this->as($this->a)->putJson("/api/admin/orders/{$order->id}/tracking", ['tracking_company' => 'DHL', 'tracking_number' => 'X2'])
            ->assertOk()->assertJsonPath('data.tracking_number', 'X2');
        $res = $this->as($this->a)->postJson("/api/admin/orders/{$order->id}/comments", ['message' => '  客户来电要求改地址  '])->assertOk();
        $res->assertJsonPath('data.events.0.type', 'comment')
            ->assertJsonPath('data.events.0.message', '客户来电要求改地址')
            ->assertJsonPath('data.events.0.user', 'M');
    }

    public function test_other_stores_cannot_touch_the_order(): void
    {
        $order = $this->checkout('test');
        $b = $this->register('owner@shop-b.test', 'Shop B');

        $this->as($b)->postJson("/api/admin/orders/{$order->id}/refunds", ['amount' => '1'])->assertNotFound();
        $this->as($b)->postJson("/api/admin/orders/{$order->id}/cancel", ['reason' => 'other'])->assertNotFound();
        $this->as($b)->postJson("/api/admin/orders/{$order->id}/comments", ['message' => 'x'])->assertNotFound();
        $this->assertSame('paid', $order->fresh()->financial_status);
    }
}
