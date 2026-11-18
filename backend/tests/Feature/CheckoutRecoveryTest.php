<?php

namespace Tests\Feature;

use App\Marketing\CheckoutRecovery;
use App\Models\Cart;
use App\Models\Order;
use App\Models\ShippingRate;
use App\Notifications\RecoveryMail;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CheckoutRecoveryTest extends TestCase
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
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $res = $this->postJson('/api/admin/auth/register', [
            'name' => 'M', 'email' => 'owner@shop-a.test', 'password' => 'secret-pass', 'store_name' => 'Shop A',
        ])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
        $product = $this->as()->postJson('/api/admin/products', ['title' => 'Silk Scarf', 'price' => 40, 'inventory' => 20, 'status' => 'active'])->assertCreated();
        $this->variantId = $product->json('data.variants.0.id');
        $this->as()->putJson('/api/admin/settings/languages', ['default' => 'en', 'published' => ['fr']])->assertOk();
    }

    private function as(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->a['token'], 'X-Store-Id' => (string) $this->a['store_id'], 'Accept' => 'application/json']);
    }

    /** Shopper adds to cart, opens checkout and types their email (the page posts it in the background). */
    private function startCheckout(string $email = 'buyer@example.com', string $prefix = ''): string
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $cart = $this->post(self::HOST.$prefix.'/cart/add', ['variant_id' => $this->variantId, 'quantity' => 2])->getCookie('lumee_cart', false)->getValue();
        $this->withUnencryptedCookie('lumee_cart', $cart)->get(self::HOST.$prefix.'/checkout')->assertOk()->assertSee('checkout\/contact', false);
        $this->withCredentials()->withUnencryptedCookie('lumee_cart', $cart)->postJson(self::HOST.$prefix.'/checkout/contact', ['email' => $email])->assertOk();

        return Cart::withoutGlobalScope('store')->latest('id')->value('token');   // the cookie holds it encrypted
    }

    private function age(string $token, int $hours): void
    {
        Cart::withoutGlobalScope('store')->where('token', $token)->update(['updated_at' => now()->subHours($hours)]);
    }

    public function test_reminder_is_sent_once_after_the_delay_and_the_link_restores_the_cart(): void
    {
        $token = $this->startCheckout('Buyer@Example.com');
        $cart = Cart::withoutGlobalScope('store')->where('token', $token)->sole();
        $this->assertSame(['buyer@example.com', 'en'], [$cart->email, $cart->locale]);
        $this->assertNotNull($cart->checkout_started_at);

        $this->age($token, 2);
        $this->assertSame(0, app(CheckoutRecovery::class)->run());   // default delay is 4 hours

        $this->age($token, 5);
        $this->artisan('lumee:recover-checkouts')->expectsOutput('Sent 1 abandoned checkout email(s).')->assertSuccessful();
        Mail::assertSent(RecoveryMail::class, function (RecoveryMail $m) use ($token) {
            $html = $m->render();

            return $m->hasTo('buyer@example.com') && $m->envelope()->subject === 'You left something in your bag'
                && str_contains($html, 'Silk Scarf') && str_contains($html, "/cart/recover/{$token}") && str_contains($html, '$80.00');
        });
        $this->assertSame(0, app(CheckoutRecovery::class)->run());   // only once
        Mail::assertSentCount(1);

        // Link in the email: restores the cart in a fresh browser and goes to checkout
        $this->flushHeaders();
        $res = $this->get(self::HOST."/cart/recover/{$token}")->assertRedirect('/checkout');
        $this->assertStringEndsWith($token, $res->getCookie('lumee_cart')->getValue());
        $this->get(self::HOST.'/cart/recover/'.\Illuminate\Support\Str::uuid())->assertRedirect('/cart');

        // Buying now counts as a recovered order
        $this->withCookie('lumee_cart', $token)->post(self::HOST.'/checkout', [
            'email' => 'buyer@example.com', 'first_name' => 'A', 'last_name' => 'B', 'address1' => '1 St', 'city' => 'X', 'zip' => '1', 'country' => 'US',
            'shipping_rate_id' => ShippingRate::withoutGlobalScope('store')->value('id'), 'payment_gateway' => 'test',
        ])->assertRedirect();
        $order = Order::withoutGlobalScope('store')->sole();
        $this->assertTrue($order->recovered);
        $this->as()->getJson('/api/admin/abandoned-checkouts')->assertOk()
            ->assertJsonPath('stats.recovered_orders', 1)->assertJsonPath('stats.recovered_revenue', \App\Support\Money::format($order->total))
            ->assertJsonCount(0, 'data');
    }

    public function test_no_reminder_when_disabled_already_ordered_too_old_or_no_email(): void
    {
        $noEmail = $this->startCheckout();
        Cart::withoutGlobalScope('store')->where('token', $noEmail)->update(['email' => null]);
        $this->age($noEmail, 5);

        $old = $this->startCheckout('old@example.com');
        $this->age($old, 24 * 8);

        $bought = $this->startCheckout('bought@example.com');
        $this->age($bought, 5);
        Order::withoutGlobalScope('store')->forceCreate([
            'store_id' => $this->a['store_id'], 'token' => (string) \Illuminate\Support\Str::uuid(), 'number' => 1001, 'email' => 'bought@example.com',
            'currency' => 'USD', 'subtotal' => 100, 'total' => 100, 'financial_status' => 'pending', 'fulfillment_status' => 'unfulfilled',
            'payment_gateway' => 'manual', 'shipping_address' => [], 'created_at' => now(),
        ]);

        $later = $this->startCheckout('later@example.com');
        $this->age($later, 5);
        $this->as()->putJson('/api/admin/abandoned-checkouts/settings', ['enabled' => false, 'delay_hours' => 4, 'discount_code' => null])->assertOk();
        $this->assertSame(0, app(CheckoutRecovery::class)->run());

        $this->as()->putJson('/api/admin/abandoned-checkouts/settings', ['enabled' => true, 'delay_hours' => 4, 'discount_code' => null])->assertOk();
        $this->assertSame(1, app(CheckoutRecovery::class)->run());
        Mail::assertSent(RecoveryMail::class, fn ($m) => $m->hasTo('later@example.com'));
        Mail::assertNotSent(RecoveryMail::class, fn ($m) => $m->hasTo('bought@example.com') || $m->hasTo('old@example.com'));
    }

    public function test_settings_discount_code_and_french_email(): void
    {
        $this->as()->putJson('/api/admin/abandoned-checkouts/settings', ['enabled' => true, 'delay_hours' => 1, 'discount_code' => 'nope'])
            ->assertStatus(422)->assertJsonValidationErrors('discount_code');
        $this->as()->putJson('/api/admin/abandoned-checkouts/settings', ['enabled' => true, 'delay_hours' => 3, 'discount_code' => null])->assertStatus(422);
        $this->as()->postJson('/api/admin/discounts', ['code' => 'COMEBACK10', 'type' => 'percentage', 'value' => 10])->assertCreated();
        $this->as()->putJson('/api/admin/abandoned-checkouts/settings', ['enabled' => true, 'delay_hours' => 1, 'discount_code' => 'comeback10'])
            ->assertOk()->assertJsonPath('settings.discount_code', 'COMEBACK10');

        $token = $this->startCheckout('fr@example.com', '/fr');
        $this->age($token, 2);
        $this->as()->getJson('/api/admin/abandoned-checkouts')->assertOk()
            ->assertJsonPath('data.0.email', 'fr@example.com')->assertJsonPath('data.0.item_count', 2)->assertJsonPath('data.0.recovery_sent_at', null);

        $this->assertSame(1, app(CheckoutRecovery::class)->run());
        Mail::assertSent(RecoveryMail::class, function (RecoveryMail $m) use ($token) {
            $html = $m->render();

            return str_contains($html, 'COMEBACK10') && str_contains($html, "/fr/cart/recover/{$token}")
                && $m->envelope()->subject === 'Vous avez oublié quelque chose dans votre panier';
        });
        $this->assertNotNull($this->as()->getJson('/api/admin/abandoned-checkouts')->json('data.0.recovery_sent_at'));
    }
}
