<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderNotification;
use App\Models\ProductVariant;
use App\Models\ShippingRate;
use App\Notifications\OrderMail;
use App\Notifications\SendOrderEmail;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class EmailNotificationTest extends TestCase
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

        return $this->withHeaders(['Authorization' => 'Bearer '.$m['token'], 'X-Store-Id' => (string) $m['store_id']]);
    }

    private function checkout(string $gateway, array $overrides = []): Order
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $res = $this->post(self::HOST.'/cart/add', ['variant_id' => $this->variantId, 'quantity' => 1]);
        $cart = $res->getCookie('lumee_cart', false)->getValue();
        $rate = ShippingRate::withoutGlobalScope('store')->where('store_id', $this->a['store_id'])->where('price', '>', 0)->first();

        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/checkout', array_merge([
            'email' => 'buyer@example.com', 'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'address1' => '1 Main St', 'city' => 'Austin', 'zip' => '73301', 'country' => 'US',
            'shipping_rate_id' => $rate->id, 'payment_gateway' => $gateway,
        ], $overrides))->assertRedirect();

        return Order::withoutGlobalScope('store')->latest('id')->firstOrFail();
    }

    private function sentTypes(): array
    {
        return Mail::sent(OrderMail::class)->map(fn (OrderMail $m) => $m->type.' → '.$m->to[0]['address'])->sort()->values()->all();
    }

    public function test_bank_transfer_order_emails_buyer_instructions_and_alerts_merchant(): void
    {
        $order = $this->checkout('manual');

        $this->assertSame([
            'merchant_new_order → owner@shop-a.test',
            'order_confirmation → buyer@example.com',
        ], $this->sentTypes());

        Mail::assertSent(OrderMail::class, function (OrderMail $m) {
            if ($m->type !== 'order_confirmation') {
                return false;
            }
            $html = $m->render();

            return $m->hasSubject('Order #1001 received — payment instructions inside')
                && $m->hasFrom('no-reply@lumee.shop', 'Shop A')       // platform address, store name
                && $m->hasReplyTo('owner@shop-a.test')
                && str_contains($html, 'complete your payment')
                && str_contains($html, 'Rose Quartz Ring')
                && str_contains($html, '$49.90');                        // total incl. $9.90 shipping
        });

        $this->assertSame(['sent', 'sent'], OrderNotification::withoutGlobalScope('store')->pluck('status')->all());

        // Merchant marks it paid → buyer gets "payment received", nobody gets a second confirmation
        $this->as($this->a)->postJson("/api/admin/orders/{$order->id}/mark-paid")->assertOk()
            ->assertJsonCount(3, 'data.notifications');
        Mail::assertSent(OrderMail::class, fn ($m) => $m->type === 'payment_received' && $m->hasTo('buyer@example.com'));
        $this->assertCount(1, Mail::sent(OrderMail::class, fn ($m) => $m->type === 'order_confirmation'));
    }

    public function test_paid_order_gets_confirmation_once_and_shipping_email_with_tracking(): void
    {
        $order = $this->checkout('test');   // instant payment

        Mail::assertSent(OrderMail::class, fn ($m) => $m->type === 'order_confirmation' && $m->hasSubject('Order #1001 confirmed'));
        Mail::assertSent(OrderMail::class, fn ($m) => $m->type === 'merchant_new_order' && str_contains($m->render(), '去后台发货'));
        Mail::assertNotSent(OrderMail::class, fn ($m) => $m->type === 'payment_received');

        // Paying again (e.g. a webhook retry) must not re-send anything
        $order->fresh()->markPaid('again');
        $this->assertCount(2, Mail::sent(OrderMail::class));

        $this->as($this->a)->postJson("/api/admin/orders/{$order->id}/fulfill", ['tracking_company' => 'DHL', 'tracking_number' => 'JD0001'])->assertOk();
        Mail::assertSent(OrderMail::class, function (OrderMail $m) {
            return $m->type === 'shipping_confirmation'
                && $m->hasSubject('Your order #1001 is on its way')
                && str_contains($m->render(), 'JD0001')
                && str_contains($m->render(), '17track.net/en#nums=JD0001');
        });
    }

    public function test_disabled_emails_are_not_sent_but_can_be_resent_manually(): void
    {
        $this->as($this->a)->putJson('/api/admin/settings/notifications', [
            'sender_name' => 'Aurora Atelier',
            'reply_to' => 'hello@shop-a.test',
            'merchant_email' => 'orders@shop-a.test',
            'enabled' => ['order_confirmation' => false, 'merchant_new_order' => true],
        ])->assertOk()
            ->assertJsonPath('settings.enabled.order_confirmation', false)
            ->assertJsonPath('settings.enabled.shipping_confirmation', true);   // untouched → default on

        $order = $this->checkout('manual');
        $this->assertSame(['merchant_new_order → orders@shop-a.test'], $this->sentTypes());

        $this->as($this->a)->postJson("/api/admin/orders/{$order->id}/notifications/order_confirmation")->assertStatus(202);
        Mail::assertSent(OrderMail::class, fn ($m) => $m->type === 'order_confirmation'
            && $m->hasFrom('no-reply@lumee.shop', 'Aurora Atelier') && $m->hasReplyTo('hello@shop-a.test'));
    }

    public function test_settings_validation_and_store_isolation(): void
    {
        $this->as($this->a)->putJson('/api/admin/settings/notifications', [
            'sender_name' => '', 'reply_to' => 'not-an-email', 'merchant_email' => 'x', 'enabled' => [],
        ])->assertStatus(422)->assertJsonValidationErrors(['sender_name', 'reply_to', 'merchant_email']);

        $order = $this->checkout('manual');
        $b = $this->register('owner@shop-b.test', 'Shop B');
        $this->as($b)->postJson("/api/admin/orders/{$order->id}/notifications/order_confirmation")->assertNotFound();
        $this->as($b)->getJson('/api/admin/settings/notifications')->assertJsonPath('settings.merchant_email', 'owner@shop-b.test');
    }

    public function test_preview_and_test_send(): void
    {
        // No orders yet → sample order
        $html = $this->as($this->a)->get('/api/admin/notifications/order_confirmation/preview')->assertOk()->getContent();
        $this->assertStringContainsString('Amethyst Butterfly Necklace', $html);
        $this->assertStringContainsString('Shop A', $html);

        $this->as($this->a)->get('/api/admin/notifications/nope/preview')->assertNotFound();

        $this->as($this->a)->postJson('/api/admin/notifications/shipping_confirmation/test')->assertOk();
        Mail::assertSent(OrderMail::class, fn ($m) => $m->type === 'shipping_confirmation' && $m->hasTo('owner@shop-a.test'));
        $this->assertDatabaseCount('orders', 0);   // preview/test never create orders
    }

    public function test_customer_input_is_escaped_in_emails(): void
    {
        $this->checkout('manual', ['first_name' => '<img src=x onerror=alert(1)>']);

        Mail::assertSent(OrderMail::class, function (OrderMail $m) {
            $html = $m->render();

            return ! str_contains($html, '<img src=x onerror') && str_contains($html, '&lt;img src=x onerror');
        });
    }

    public function test_failed_delivery_is_recorded(): void
    {
        $order = $this->checkout('manual');
        $log = OrderNotification::withoutGlobalScope('store')->where('order_id', $order->id)->first();

        (new SendOrderEmail($log->id))->failed(new RuntimeException('SMTP 550 mailbox unavailable'));

        $this->as($this->a)->getJson("/api/admin/orders/{$order->id}")
            ->assertJsonFragment(['status' => 'failed', 'error' => 'SMTP 550 mailbox unavailable']);
    }
}
