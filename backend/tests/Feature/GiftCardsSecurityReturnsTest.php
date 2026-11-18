<?php

namespace Tests\Feature;

use App\Models\GiftCard;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\ReturnRequest;
use App\Models\ShippingRate;
use App\Models\User;
use App\Notifications\GiftCardMail;
use App\Notifications\ReturnMail;
use App\Security\Totp;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class GiftCardsSecurityReturnsTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private array $a;

    private array $b;

    private int $variantId;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.payments.test_gateway' => true]);
        Mail::fake();
        $this->a = $this->register('a@example.com', 'Shop A');
        $this->b = $this->register('b@example.com', 'Shop B');
        $this->productId = $this->as($this->a)->postJson('/api/admin/products', ['title' => 'Linen Shirt', 'price' => 50, 'inventory' => 50, 'status' => 'active'])->json('data.id');
        $this->variantId = ProductVariant::withoutGlobalScope('store')->where('product_id', $this->productId)->value('id');
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

    private function cart(): string
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        return $this->post(self::HOST.'/cart/add', ['variant_id' => $this->variantId, 'quantity' => 1])->getCookie('lumee_cart', false)->getValue();
    }

    private function checkout(string $cart)
    {
        $rate = ShippingRate::withoutGlobalScope('store')->where('store_id', $this->a['store_id'])->where('price', 990)->first();

        return $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/checkout', [
            'email' => 'buyer@example.com', 'first_name' => 'A', 'last_name' => 'B', 'address1' => '1 St', 'city' => 'X',
            'zip' => '1', 'country' => 'US', 'shipping_rate_id' => $rate->id, 'payment_gateway' => 'test',
        ]);
    }

    private function card(float $value, array $extra = []): array
    {
        return $this->as($this->a)->postJson('/api/admin/gift-cards', ['value' => $value, ...$extra])->assertCreated()->json('data');
    }

    // ── Gift cards ─────────────────────────────────────────────────────

    public function test_issue_and_email_gift_card(): void
    {
        $c = $this->card(25, ['recipient_email' => 'Friend@Example.com', 'recipient_name' => 'Jo', 'message' => 'Happy birthday', 'send' => true]);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4} [A-Z2-9]{4} [A-Z2-9]{4} [A-Z2-9]{4}$/', $c['code']);
        $this->assertSame('25.00', $c['balance']);
        $this->assertSame('active', $c['status']);
        Mail::assertSent(GiftCardMail::class, fn ($m) => $m->hasTo('friend@example.com'));

        // Code isn't exposed afterwards; custom codes must be unique
        $this->as($this->a)->getJson('/api/admin/gift-cards')->assertJsonMissingPath('data.0.code')->assertJsonPath('summary.outstanding', '25.00');
        $this->card(10, ['code' => 'vip-2026-abcd']);
        $this->as($this->a)->postJson('/api/admin/gift-cards', ['value' => 5, 'code' => 'VIP2026ABCD'])->assertStatus(422);
        $this->as($this->a)->getJson('/api/admin/gift-cards?q=abcd')->assertJsonCount(1, 'data');
        $this->as($this->b)->getJson("/api/admin/gift-cards/{$c['id']}")->assertNotFound();
    }

    public function test_gift_card_pays_part_of_an_order_and_comes_back_on_cancel(): void
    {
        $code = $this->card(20)['code'];
        $cart = $this->cart();
        $this->withUnencryptedCookie('lumee_cart', $cart)->from(self::HOST.'/cart')->post(self::HOST.'/cart/discount', ['code' => strtolower($code)])
            ->assertSessionHas('status');
        $this->withUnencryptedCookie('lumee_cart', $cart)->get(self::HOST.'/checkout')->assertOk()->assertSee('Gift card');
        $this->checkout($cart)->assertRedirect();

        $order = Order::withoutGlobalScope('store')->latest('id')->first();
        $this->assertSame(2000, (int) $order->gift_card_total);
        $this->assertSame(5990 - 2000, (int) $order->total);
        $card = GiftCard::withoutGlobalScope('store')->first();
        $this->assertSame(0, (int) $card->balance);
        $this->assertSame('used_up', $card->status());

        // Cancel → balance restored and logged
        $this->as($this->a)->postJson("/api/admin/orders/{$order->id}/cancel", ['reason' => 'customer', 'restock' => true, 'refund' => false])->assertOk();
        $this->assertSame(2000, (int) $card->fresh()->balance);
        $tx = $this->as($this->a)->getJson("/api/admin/gift-cards/{$card->id}")->json('data.transactions');
        $this->assertSame(['restored', 'spent', 'issued'], array_column($tx, 'kind'));
    }

    public function test_gift_card_covering_the_whole_order_marks_it_paid(): void
    {
        $code = $this->card(100)['code'];
        $cart = $this->cart();
        $this->withUnencryptedCookie('lumee_cart', $cart)->from(self::HOST.'/cart')->post(self::HOST.'/cart/discount', ['code' => $code]);
        $this->checkout($cart)->assertRedirectContains('/orders/');

        $order = Order::withoutGlobalScope('store')->latest('id')->first();
        $this->assertSame('paid', $order->financial_status);
        $this->assertSame('gift_card', $order->payment_gateway);
        $this->assertSame(0, (int) $order->total);
        $this->assertSame('40.10', $this->as($this->a)->getJson('/api/admin/gift-cards')->json('data.0.balance'));
    }

    public function test_disabled_or_foreign_gift_cards_are_rejected(): void
    {
        $c = $this->card(20);
        $this->as($this->a)->patchJson("/api/admin/gift-cards/{$c['id']}", ['disabled' => true])->assertJsonPath('data.status', 'disabled');
        $cart = $this->cart();
        $this->withUnencryptedCookie('lumee_cart', $cart)->from(self::HOST.'/cart')->post(self::HOST.'/cart/discount', ['code' => $c['code']])
            ->assertSessionHasErrors('discount_code');

        // Balance adjustment is logged
        $this->as($this->a)->patchJson("/api/admin/gift-cards/{$c['id']}", ['disabled' => false, 'balance' => 5, 'reason' => 'Goodwill'])
            ->assertJsonPath('data.balance', '5.00')->assertJsonPath('data.transactions.0.kind', 'adjusted');
    }

    // ── Two-step sign-in ───────────────────────────────────────────────

    public function test_two_factor_sign_in(): void
    {
        $this->as($this->a)->postJson('/api/admin/account/two-factor', ['password' => 'wrong'])->assertStatus(422);
        $secret = str_replace(' ', '', $this->as($this->a)->postJson('/api/admin/account/two-factor', ['password' => 'secret-pass'])->assertOk()->json('secret'));
        $this->as($this->a)->postJson('/api/admin/account/two-factor/confirm', ['code' => '000000'])->assertStatus(422);
        $codes = $this->as($this->a)->postJson('/api/admin/account/two-factor/confirm', ['code' => Totp::code($secret)])->assertOk()->json('recovery_codes');
        $this->assertCount(8, $codes);
        $this->assertNotSame($secret, DB::table('users')->where('email', 'a@example.com')->value('two_factor_secret'));   // encrypted at rest

        // Password alone no longer gives a token
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $res = $this->postJson('/api/admin/auth/login', ['email' => 'a@example.com', 'password' => 'secret-pass'])->assertOk()
            ->assertJsonPath('two_factor', true)->assertJsonMissingPath('token');
        $challenge = $res->json('challenge');
        $this->postJson('/api/admin/auth/two-factor', ['challenge' => $challenge, 'code' => '123456'])->assertStatus(422);
        $this->postJson('/api/admin/auth/two-factor', ['challenge' => 'forged', 'code' => Totp::code($secret)])->assertStatus(422);
        $this->postJson('/api/admin/auth/two-factor', ['challenge' => $challenge, 'code' => Totp::code($secret)])->assertOk()->assertJsonStructure(['token']);

        // Recovery codes work once
        $this->postJson('/api/admin/auth/two-factor', ['challenge' => $challenge, 'recovery_code' => strtoupper($codes[0])])->assertOk();
        $this->postJson('/api/admin/auth/two-factor', ['challenge' => $challenge, 'recovery_code' => $codes[0]])->assertStatus(422);
        $this->as($this->a)->getJson('/api/admin/account/two-factor')->assertJsonPath('enabled', true)->assertJsonPath('recovery_codes_left', 7);

        // Turning it off needs the password
        $this->as($this->a)->deleteJson('/api/admin/account/two-factor', ['password' => 'secret-pass'])->assertJsonPath('enabled', false);
        $this->postJson('/api/admin/auth/login', ['email' => 'a@example.com', 'password' => 'secret-pass'])->assertJsonStructure(['token']);
    }

    // ── Activity log ───────────────────────────────────────────────────

    public function test_activity_log_records_changes_but_not_autosave(): void
    {
        $detail = $this->as($this->a)->getJson("/api/admin/products/{$this->productId}")->json('data');
        $this->as($this->a)->putJson("/api/admin/products/{$this->productId}", [...$detail, 'title' => 'Linen Shirt 2'])->assertOk();
        $theme = $this->as($this->a)->getJson('/api/admin/theme')->json('config');
        $this->as($this->a)->putJson('/api/admin/theme', $theme)->assertOk();   // autosave: not logged
        $this->as($this->a)->get('/api/admin/orders/export')->assertOk();
        $this->as($this->a)->putJson("/api/admin/products/{$this->productId}", ['title' => ''])->assertStatus(422);   // failed: not logged

        $log = $this->as($this->a)->getJson('/api/admin/activity')->assertOk()->json('data');
        $actions = array_column($log, 'action');
        $this->assertContains('products.update', $actions);
        $this->assertContains('orders.export', $actions);
        $this->assertContains('products.store', $actions);
        $this->assertNotContains('theme.update', $actions);
        $update = collect($log)->firstWhere('action', 'products.update');
        $this->assertSame('编辑商品', $update['label']);
        $this->assertSame('Linen Shirt 2', $update['subject']);
        $this->assertSame('M', $update['user']['name']);
        $this->as($this->b)->getJson('/api/admin/activity')->assertJsonMissing(['action' => 'products.update']);

        // Sign-ins are account-level: they show in the person's store log
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->postJson('/api/admin/auth/login', ['email' => 'a@example.com', 'password' => 'secret-pass'])->assertOk();
        $this->assertSame('auth.login', $this->as($this->a)->getJson('/api/admin/activity?area=auth')->json('data.0.action'));
    }

    // ── Returns ────────────────────────────────────────────────────────

    public function test_return_request_flow(): void
    {
        $cart = $this->cart();
        $this->checkout($cart);
        $order = Order::withoutGlobalScope('store')->latest('id')->first();
        $order->markPaid();
        $item = $order->items()->first();
        $page = self::HOST."/orders/{$order->token}";

        // Not shipped yet → no form, and posting is refused
        $this->get($page)->assertOk()->assertDontSee('Request a return');
        $this->from($page)->post("$page/returns", ['items' => [$item->id => 1], 'reason' => 'size'])->assertSessionHasErrors('items');

        $this->as($this->a)->postJson("/api/admin/orders/{$order->id}/fulfill", ['tracking_number' => '', 'notify' => false])->assertOk();
        $this->flushHeaders();
        $this->get($page)->assertSee('Request a return');
        $this->from($page)->post("$page/returns", ['items' => [$item->id => 0], 'reason' => 'size'])->assertSessionHasErrors('items');
        $this->from($page)->post("$page/returns", ['items' => [$item->id => 5], 'reason' => 'size', 'note' => 'Too small'])->assertSessionHas('status');
        Mail::assertSent(ReturnMail::class, fn ($m) => $m->audience === 'merchant');
        $this->assertSame(1, ReturnRequest::withoutGlobalScope('store')->first()->items[0]['quantity']);   // capped at what was bought
        $this->from($page)->post("$page/returns", ['items' => [$item->id => 1], 'reason' => 'size'])->assertSessionHasErrors('items');   // already open

        $list = $this->as($this->a)->getJson('/api/admin/returns?status=requested')->assertOk()->assertJsonCount(1, 'data');
        $id = $list->json('data.0.id');
        $this->assertSame('Too small', $list->json('data.0.customer_note'));
        $this->as($this->a)->putJson("/api/admin/returns/{$id}", ['status' => 'completed'])->assertStatus(422);   // must approve first
        $this->as($this->a)->putJson("/api/admin/returns/{$id}", ['status' => 'approved', 'instructions' => 'Ship to 1 Main St'])
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.return_instructions', 'Ship to 1 Main St');
        Mail::assertSent(ReturnMail::class, fn ($m) => $m->audience === 'customer' && $m->hasTo('buyer@example.com'));
        $this->flushHeaders();
        $this->get($page)->assertSee('Ship to 1 Main St');
        $this->as($this->b)->putJson("/api/admin/returns/{$id}", ['status' => 'completed'])->assertNotFound();
        $this->as($this->a)->putJson("/api/admin/returns/{$id}", ['status' => 'completed'])->assertJsonPath('data.status', 'completed');

        // Settings: switch off
        $this->as($this->a)->putJson('/api/admin/returns/settings', ['enabled' => false, 'window_days' => 14])->assertJsonPath('settings.window_days', 14);
    }

    // ── Storefront extras ──────────────────────────────────────────────

    public function test_related_products_and_wishlist_endpoint(): void
    {
        $this->as($this->a)->postJson('/api/admin/products', ['title' => 'Silk Scarf', 'price' => 30, 'inventory' => 5, 'status' => 'active']);
        $this->as($this->a)->postJson('/api/admin/products', ['title' => 'Hidden Draft', 'price' => 30, 'inventory' => 5, 'status' => 'draft']);
        $this->flushHeaders();
        $this->get(self::HOST.'/products/linen-shirt')->assertOk()->assertSee('You may also like')->assertSee('Silk Scarf')->assertDontSee('Hidden Draft')
            ->assertSee('data-wish="linen-shirt"', false);
        $this->getJson(self::HOST.'/wishlist/products?handles=silk-scarf,hidden-draft,nope')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Silk Scarf');
        $this->get(self::HOST.'/wishlist')->assertOk()->assertSee('noindex', false);
    }
}
