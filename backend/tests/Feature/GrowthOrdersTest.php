<?php

namespace Tests\Feature;

use App\Models\AffiliateCommission;
use App\Models\Order;
use App\Models\OrderNotification;
use App\Models\ProductVariant;
use App\Models\ShippingRate;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Pickup-ready email, affiliates, upsells and order editing. */
class GrowthOrdersTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop.lumee.localhost';

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['lumee.payments.test_gateway' => true]);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => 'o@shop.test', 'password' => 'secret-pass', 'store_name' => 'Shop'])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
    }

    private function as(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->a['token'], 'X-Store-Id' => (string) $this->a['store_id'], 'Accept' => 'application/json']);
    }

    private function product(string $title, float $price, int $stock = 50): array
    {
        return $this->as()->postJson('/api/admin/products', ['title' => $title, 'price' => $price, 'inventory' => $stock, 'status' => 'active'])->assertCreated()->json('data');
    }

    private function shopper(): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this;
    }

    /** @param array<int,int> $lines variant => qty */
    private function buy(array $lines, array $extra = [], array $cookies = [])
    {
        $this->shopper();
        $this->defaultCookies = $this->unencryptedCookies = [];
        foreach ($cookies as $k => $v) {
            $this->withCookie($k, $v);
        }
        $cart = null;
        foreach ($lines as $variant => $qty) {
            $req = $cart ? $this->withUnencryptedCookie('lumee_cart', $cart) : $this;
            $cart = $req->post(self::HOST.'/cart/add', ['variant_id' => $variant, 'quantity' => $qty])->getCookie('lumee_cart', false)?->getValue() ?? $cart;
        }

        return $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/checkout', [
            'email' => 'b@buyer.test', 'first_name' => 'B', 'last_name' => 'C', 'address1' => '1 St', 'city' => 'X', 'zip' => '1', 'country' => 'US',
            'shipping_rate_id' => ShippingRate::withoutGlobalScope('store')->where('store_id', $this->a['store_id'])->value('id'), 'payment_gateway' => 'test', ...$extra,
        ]);
    }

    private function last(): Order
    {
        return Order::withoutGlobalScope('store')->with('items')->latest('id')->firstOrFail();
    }

    public function test_pickup_orders_get_the_ready_for_pickup_email(): void
    {
        $p = $this->product('Bangle', 50);
        $this->as()->putJson('/api/admin/settings/pickup', ['enabled' => true, 'locations' => [['name' => 'Studio', 'address' => '1 High St']]])->assertOk();
        $this->buy([$p['variants'][0]['id'] => 1], ['shipping_rate_id' => -100])->assertRedirect();
        $o = $this->last();
        $this->as()->postJson("/api/admin/orders/{$o->id}/fulfill", [])->assertOk();
        $this->assertSame(['pickup_ready'], OrderNotification::withoutGlobalScope('store')->where('order_id', $o->id)->whereIn('type', ['pickup_ready', 'shipping_confirmation'])->pluck('type')->all());
        $this->as()->get('/api/admin/notifications/pickup_ready/preview')->assertOk()->assertSee('ready for pickup');
    }

    public function test_affiliate_links_commissions_refunds_and_payouts(): void
    {
        $p = $this->product('Ring', 100);
        $this->as()->postJson('/api/admin/affiliates', ['name' => 'Ana', 'email' => 'ana@blog.test', 'code' => 'Ana10', 'commission_rate' => 10])->assertCreated()
            ->assertJsonPath('data.0.code', 'ana10');
        $this->as()->postJson('/api/admin/affiliates', ['name' => 'Dup', 'email' => 'd@x.test', 'code' => 'ana10', 'commission_rate' => 5])->assertStatus(422);
        $aff = $this->as()->getJson('/api/admin/affiliates')->json('data.0');

        // Click: counted once, cookie set
        $res = $this->shopper()->get(self::HOST.'/?ref=ANA10')->assertOk();
        $cookie = $res->getCookie('lumee_ref');
        $this->assertSame((string) $aff['id'], $cookie?->getValue());
        $this->withCookie('lumee_ref', (string) $aff['id'])->get(self::HOST.'/products/ring?ref=ana10');
        $this->assertSame(1, $this->as()->getJson('/api/admin/affiliates')->json('data.0.stats.clicks'));
        $this->shopper()->get(self::HOST.'/?ref=nobody')->assertOk()->assertCookieMissing('lumee_ref');

        // Order through the link → commission on merchandise (after discounts)
        $this->buy([$p['variants'][0]['id'] => 2], [], ['lumee_ref' => (string) $aff['id']])->assertRedirect();
        $o = $this->last();
        $this->assertSame($aff['id'], (int) $o->affiliate_id);
        $c = AffiliateCommission::withoutGlobalScope('store')->sole();
        $this->assertSame(20000, $c->base_amount);
        $this->assertSame(2000, $c->amount);

        // Self-referral is ignored
        $this->buy([$p['variants'][0]['id'] => 1], ['email' => 'ana@blog.test'], ['lumee_ref' => (string) $aff['id']]);
        $this->assertNull($this->last()->affiliate_id);

        // Partial refund shrinks it; can't pay inside the return window
        $this->as()->postJson("/api/admin/orders/{$o->id}/refunds", ['amount' => 50])->assertOk();
        $this->assertSame(1500, $c->fresh()->amount);
        $this->as()->postJson("/api/admin/affiliates/{$aff['id']}/pay")->assertStatus(422);
        AffiliateCommission::withoutGlobalScope('store')->update(['created_at' => now()->subDays(31)]);
        $this->as()->getJson('/api/admin/affiliates')->assertJsonPath('data.0.stats.payable', '15.00');
        $this->as()->postJson("/api/admin/affiliates/{$aff['id']}/pay", ['reference' => 'PP-9'])->assertOk()->assertJsonPath('data.0.stats.paid', '15.00');
        $this->assertSame('paid', $c->fresh()->status);

        // Affiliate's private page
        $token = \App\Models\Affiliate::withoutGlobalScope('store')->find($aff['id'])->token;
        $this->shopper()->get(self::HOST.'/affiliate/'.$token)->assertOk()->assertSee('?ref=ana10')->assertSee('$15.00');
        $this->get(self::HOST.'/affiliate/'.str_repeat('x', 48))->assertNotFound();

        // Cancelled order: commission voided
        $this->buy([$p['variants'][0]['id'] => 1], [], ['lumee_ref' => (string) $aff['id']]);
        $o2 = $this->last();
        $this->as()->postJson("/api/admin/orders/{$o2->id}/cancel", ['reason' => 'customer', 'refund' => true, 'restock' => true])->assertOk();
        $this->assertSame('void', AffiliateCommission::withoutGlobalScope('store')->where('order_id', $o2->id)->value('status'));
    }

    public function test_frequently_bought_together_and_cart_suggestions(): void
    {
        $ring = $this->product('Ring', 100);
        $chain = $this->product('Chain', 40);
        $box = $this->product('Gift Box', 5);
        // Co-purchase history: ring + box
        $this->buy([$ring['variants'][0]['id'] => 1, $box['variants'][0]['id'] => 1])->assertRedirect();

        $this->shopper()->get(self::HOST.'/products/ring')->assertSee('Frequently bought together')->assertSee('Gift Box');
        // Hand-picked companions win
        $this->as()->putJson("/api/admin/products/{$ring['id']}", ['upsell_ids' => [$chain['id']]])->assertOk()->assertJsonPath('data.upsells.0.title', 'Chain');
        \Illuminate\Support\Facades\Cache::flush();
        $page = $this->shopper()->get(self::HOST.'/products/ring');
        $page->assertSee('Frequently bought together');
        $this->assertStringContainsString('value="'.$chain['variants'][0]['id'].'"', $page->getContent());

        // Add several at once; cart suggests companions not already in it
        $cart = $this->shopper()->post(self::HOST.'/cart/add', ['variant_id' => $ring['variants'][0]['id'], 'quantity' => 1])->getCookie('lumee_cart', false)->getValue();
        $this->withUnencryptedCookie('lumee_cart', $cart)->get(self::HOST.'/cart')->assertSee('You might also like')->assertSee('Chain');
        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/cart/add-many', ['variant_ids' => [$chain['variants'][0]['id']]])->assertRedirect('/cart');
        $this->withUnencryptedCookie('lumee_cart', $cart)->get(self::HOST.'/cart')->assertDontSee('You might also like');
    }

    public function test_order_editing_refunds_or_records_balance(): void
    {
        $ring = $this->product('Ring', 100, 10);
        $chain = $this->product('Chain', 40, 10);
        $this->buy([$ring['variants'][0]['id'] => 2])->assertRedirect();
        $o = $this->last();
        $paid = (int) $o->total;
        $item = $o->items->first();

        // Cheaper: refund the difference, stock returns
        $res = $this->as()->postJson("/api/admin/orders/{$o->id}/edit", ['items' => [['id' => $item->id, 'quantity' => 1]]])->assertOk();
        $this->assertSame(10000, $res->json('result.refunded'));
        $o->refresh();
        $this->assertSame($paid, (int) $o->total);
        $this->assertSame(10000, (int) $o->refunded_total);
        $this->assertSame(9, ProductVariant::withoutGlobalScope('store')->find($ring['variants'][0]['id'])->inventory_quantity);

        // Dearer: balance due, then marked received
        $res = $this->as()->postJson("/api/admin/orders/{$o->id}/edit", ['add' => [['variant_id' => $chain['variants'][0]['id'], 'quantity' => 2]]])->assertOk();
        $this->assertSame(8000, $res->json('result.balance_due'));
        $this->as()->getJson("/api/admin/orders/{$o->id}")->assertJsonPath('data.balance_due', '80.00')->assertJsonPath('data.edits_count', 2);
        $this->shopper()->get(self::HOST."/orders/{$o->token}")->assertSee('Balance due');
        $this->as()->postJson("/api/admin/orders/{$o->id}/balance-received")->assertOk()->assertJsonPath('data.balance_due', '0.00');
        $this->assertSame($paid + 8000, (int) $o->fresh()->total);

        // Validation: stock, last item, shipped orders
        $this->as()->postJson("/api/admin/orders/{$o->id}/edit", ['add' => [['variant_id' => $chain['variants'][0]['id'], 'quantity' => 50]]])->assertStatus(422);
        $this->as()->postJson("/api/admin/orders/{$o->id}/edit", ['items' => $o->fresh()->items->map(fn ($i) => ['id' => $i->id, 'quantity' => 0])->all()])->assertStatus(422);
        $this->as()->postJson("/api/admin/orders/{$o->id}/fulfill", [])->assertOk();
        $this->as()->postJson("/api/admin/orders/{$o->id}/edit", ['add' => [['variant_id' => $chain['variants'][0]['id'], 'quantity' => 1]]])->assertStatus(422);
        $this->assertSame(1, \App\Models\OrderEvent::where('order_id', $o->id)->where('type', 'edited')->where('message', 'like', '%退还差价%')->count());
    }
}
