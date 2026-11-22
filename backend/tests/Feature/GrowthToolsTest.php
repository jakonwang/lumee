<?php

namespace Tests\Feature;

use App\Loyalty\Points;
use App\Models\Customer;
use App\Models\GiftCard;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\ShippingRate;
use App\Models\SmsMessage;
use App\Models\Store;
use App\Notifications\GiftCardMail;
use App\Notifications\Sms;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class GrowthToolsTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.payments.test_gateway' => true]);
        Mail::fake();
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => 'a@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop A'])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
    }

    private function as(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->a['token'], 'X-Store-Id' => (string) $this->a['store_id']]);
    }

    private function product(array $attrs): int
    {
        $id = $this->as()->postJson('/api/admin/products', ['price' => 50, 'inventory' => 50, 'status' => 'active', ...$attrs])->assertCreated()->json('data.id');

        return ProductVariant::withoutGlobalScope('store')->where('product_id', $id)->value('id');
    }

    /** Storefront checkout; returns the order (still pending until markPaid). */
    private function buy(int $variantId, int $qty = 1, array $address = []): Order
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $cart = $this->post(self::HOST.'/cart/add', ['variant_id' => $variantId, 'quantity' => $qty])->getCookie('lumee_cart', false)->getValue();
        $rate = ShippingRate::withoutGlobalScope('store')->where('store_id', $this->a['store_id'])->where('price', 990)->first();
        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/checkout', [
            'email' => 'buyer@example.com', 'first_name' => 'Ada', 'last_name' => 'Lee', 'address1' => '1 St', 'city' => 'Austin',
            'zip' => '78701', 'country' => 'US', 'shipping_rate_id' => $rate->id, 'payment_gateway' => 'test', ...$address,
        ])->assertRedirect();

        return Order::withoutGlobalScope('store')->latest('id')->first();
    }

    public function test_gift_card_products_issue_cards_when_paid(): void
    {
        $variant = $this->product(['title' => 'Gift Card', 'price' => 25, 'is_gift_card' => true, 'taxable' => false]);
        $order = $this->buy($variant, 2);   // the test gateway pays straight away
        $order->markPaid();
        app(\App\GiftCards\GiftCards::class)->issuePurchased($order->fresh());   // repeated "paid" → no duplicates
        $cards = GiftCard::withoutGlobalScope('store')->get();
        $this->assertCount(2, $cards);
        $this->assertSame([2500, 2500], $cards->pluck('balance')->all());
        $this->assertSame('purchase', $cards[0]->source);
        Mail::assertSent(GiftCardMail::class, 2);
        $this->assertSame('fulfilled', $order->fresh()->fulfillment_status);   // nothing to ship
        $this->as()->getJson("/api/admin/products/".ProductVariant::withoutGlobalScope('store')->find($variant)->product_id)->assertJsonPath('data.is_gift_card', true);

        // A mixed order still needs shipping
        $shirt = $this->product(['title' => 'Shirt']);
        $order2 = $this->buy($shirt);
        $order2->markPaid();
        $this->assertSame('unfulfilled', $order2->fresh()->fulfillment_status);
    }

    public function test_gift_card_bought_for_someone_else(): void
    {
        $variant = $this->product(['title' => 'Gift Card', 'price' => 25, 'is_gift_card' => true, 'taxable' => false]);
        $shirt = $this->product(['title' => 'Shirt']);
        $this->flushHeaders();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->get(self::HOST.'/products/gift-card')->assertOk()->assertSee('name="gift[email]"', false);
        $this->get(self::HOST.'/products/shirt')->assertOk()->assertDontSee('name="gift[email]"', false);

        $cart = $this->post(self::HOST.'/cart/add', ['variant_id' => $variant, 'quantity' => 1, 'gift' => ['email' => 'Mia@Example.com', 'name' => 'Mia', 'message' => 'Happy birthday!']])
            ->getCookie('lumee_cart', false)->getValue();
        $this->post(self::HOST.'/cart/add', ['variant_id' => $shirt, 'quantity' => 1, 'gift' => ['email' => 'x@example.com']]);   // ignored: not a gift card
        $this->withUnencryptedCookie('lumee_cart', $cart)->get(self::HOST.'/cart')->assertSee('Gift for Mia');
        $this->post(self::HOST.'/cart/add', ['variant_id' => $variant, 'gift' => ['email' => 'not-an-email']])->assertSessionHasErrors('gift.email');

        $rate = ShippingRate::withoutGlobalScope('store')->where('store_id', $this->a['store_id'])->where('price', 990)->first();
        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/checkout', [
            'email' => 'buyer@example.com', 'first_name' => 'Ada', 'last_name' => 'Lee', 'address1' => '1 St', 'city' => 'Austin',
            'zip' => '78701', 'country' => 'US', 'shipping_rate_id' => $rate->id, 'payment_gateway' => 'test',
        ])->assertRedirect();
        $order = Order::withoutGlobalScope('store')->latest('id')->first();
        $order->markPaid();

        $card = GiftCard::withoutGlobalScope('store')->sole();
        $this->assertSame(['mia@example.com', 'Mia', 'Happy birthday!'], [$card->recipient_email, $card->recipient_name, $card->message]);
        Mail::assertSent(GiftCardMail::class, fn ($m) => $m->hasTo('mia@example.com') && ! $m->hasTo('buyer@example.com'));
        $this->assertNull(\App\Models\OrderItem::withoutGlobalScope('store')->where('title', 'Shirt')->value('gift'));
    }

    public function test_server_side_purchase_events(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['events_received' => 1]),
            'business-api.tiktok.com/*' => Http::response(['code' => 40001, 'message' => 'Invalid access token']),
        ]);
        $this->as()->putJson('/api/admin/settings/tracking', ['meta' => '123456789012', 'tiktok' => 'C1ABCDEFGHIJ', 'consent' => false])->assertOk();
        $this->as()->putJson('/api/admin/settings/tracking/server-events', ['meta_token' => 'EAAB-secret', 'tiktok_token' => 'tt-secret', 'test_code' => 'TEST123'])
            ->assertOk()->assertJsonPath('server_events.meta_token_set', true)->assertJsonMissing(['EAAB-secret']);
        $this->assertStringNotContainsString('EAAB-secret', json_encode(Store::find($this->a['store_id'])->settings));   // encrypted at rest

        $order = $this->buy($this->product(['title' => 'Shirt']), 1, ['phone' => '+1 555 123 4567']);
        $order->markPaid();

        Http::assertSent(function (Request $r) use ($order) {
            if (! str_contains($r->url(), 'graph.facebook.com/v19.0/123456789012/events')) {
                return false;
            }
            $e = $r['data'][0];

            return str_contains($r->url(), 'access_token=EAAB-secret') && $e['event_name'] === 'Purchase'
                && $e['event_id'] === 'order-'.$order->name() && $e['user_data']['em'][0] === hash('sha256', 'buyer@example.com')
                && $e['user_data']['ph'][0] === hash('sha256', '15551234567') && $e['custom_data']['value'] == 59.9 && $r['test_event_code'] === 'TEST123';
        });
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'tiktok.com') && $r->hasHeader('Access-Token', 'tt-secret')
            && $r['data'][0]['event'] === 'CompletePayment' && $r['data'][0]['event_id'] === 'order-'.$order->name());

        $status = $this->as()->getJson('/api/admin/settings/tracking')->json('server_events.status');
        $this->assertTrue($status['meta']['ok']);
        $this->assertFalse($status['tiktok']['ok']);
        $this->assertSame('Invalid access token', $status['tiktok']['error']);

        // Test button; removing a token
        $this->as()->postJson('/api/admin/settings/tracking/server-events/test')->assertOk()->assertJsonPath('results.meta.ok', true);
        $this->as()->putJson('/api/admin/settings/tracking/server-events', ['remove' => ['meta_token', 'tiktok_token']])->assertJsonPath('server_events.meta_token_set', false);
        $this->as()->postJson('/api/admin/settings/tracking/server-events/test')->assertStatus(422);
    }

    public function test_sms_shipping_notification(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123'], 201)]);
        $sid = 'AC'.str_repeat('a', 32);
        $this->as()->putJson('/api/admin/settings/sms', ['enabled' => true, 'account_sid' => $sid, 'from' => '+15550001111'])->assertStatus(422);   // no token yet
        $this->as()->putJson('/api/admin/settings/sms', ['enabled' => true, 'account_sid' => 'bad', 'auth_token' => 'x', 'from' => '+15550001111'])->assertJsonValidationErrors('account_sid');
        $this->as()->putJson('/api/admin/settings/sms', ['enabled' => true, 'account_sid' => $sid, 'auth_token' => 'tok', 'from' => '+15550001111',
            'events' => ['order_confirmation' => false, 'shipping_confirmation' => true]])->assertOk()->assertJsonPath('settings.auth_token_set', true)->assertJsonMissing(['auth_token' => 'tok']);

        $order = $this->buy($this->product(['title' => 'Shirt']), 1, ['phone' => '(555) 123-4567']);
        $order->markPaid();
        $this->assertSame(0, SmsMessage::withoutGlobalScope('store')->count());   // confirmation texts are off
        $this->as()->postJson("/api/admin/orders/{$order->id}/fulfill", ['tracking_company' => 'UPS', 'tracking_number' => '1Z999', 'notify' => true])->assertOk();
        $this->as()->putJson("/api/admin/orders/{$order->id}/tracking", ['tracking_company' => 'UPS', 'tracking_number' => '1Z998', 'notify' => true]);

        $msgs = SmsMessage::withoutGlobalScope('store')->get();
        $this->assertCount(1, $msgs);
        $this->assertSame('+15551234567', $msgs[0]->to);
        $this->assertSame('sent', $msgs[0]->status);
        $this->assertStringContainsString($order->name(), $msgs[0]->body);
        $this->assertStringContainsString('1Z999', $msgs[0]->body);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), "Accounts/{$sid}/Messages.json") && $r['To'] === '+15551234567' && $r->hasHeader('Authorization'));

        $this->assertSame('+447700900123', Sms::e164('07700 900123', 'GB'));
        $this->assertSame('+8613800000000', Sms::e164('0086 138 0000 0000', null));
        $this->assertNull(Sms::e164('12345', 'US'));
        $this->assertNull(Sms::e164('555 1234 567', null));
    }

    public function test_loyalty_points_earn_redeem_and_reverse(): void
    {
        $this->as()->putJson('/api/admin/loyalty', ['enabled' => true, 'earn_rate' => 2, 'redeem_points' => 100, 'min_redeem' => 50])->assertOk();
        $variant = $this->product(['title' => 'Shirt']);

        // Account holder buys → earns 2 points per unit spent (59.90 → 119)
        $this->flushHeaders();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->post(self::HOST.'/account/register', ['first_name' => 'Ada', 'last_name' => 'Lee', 'email' => 'buyer@example.com', 'password' => 'correct-horse', 'password_confirmation' => 'correct-horse']);
        $order = $this->buy($variant);
        $order->markPaid();
        $customer = Customer::withoutGlobalScope('store')->where('email', 'buyer@example.com')->first();
        $this->assertSame(119, $customer->points);
        $order->markPaid();   // idempotent
        $this->assertSame(119, $customer->fresh()->points);

        // Admin view + adjustment
        $this->as()->getJson('/api/admin/loyalty')->assertJsonPath('stats.members', 1)->assertJsonPath('stats.outstanding', 119)->assertJsonPath('top.0.points', 119);
        $this->as()->postJson("/api/admin/customers/{$customer->id}/points", ['points' => 1, 'note' => 'Goodwill'])->assertJsonPath('points', 120);
        $this->as()->postJson("/api/admin/customers/{$customer->id}/points", ['points' => -500])->assertJsonPath('points', 0);   // never below zero
        $this->as()->postJson("/api/admin/customers/{$customer->id}/points", ['points' => 300])->assertJsonPath('points', 300);

        // Redeem: 300 points → 3.00 credit as a personal gift card, applied to the bag
        $this->flushHeaders();
        $this->post(self::HOST.'/account/login', ['email' => 'buyer@example.com', 'password' => 'correct-horse']);
        $cart = $this->post(self::HOST.'/cart/add', ['variant_id' => $variant, 'quantity' => 1])->getCookie('lumee_cart', false)->getValue();
        $this->withUnencryptedCookie('lumee_cart', $cart)->get(self::HOST.'/cart')->assertSee('Use them');
        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/account/points/redeem', ['points' => 20])->assertSessionHasErrors('points');   // below minimum
        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/account/points/redeem', ['points' => 999])->assertSessionHasErrors('points');  // more than balance
        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/account/points/redeem', ['points' => 300])->assertRedirect('/cart');
        $card = GiftCard::withoutGlobalScope('store')->where('source', 'points')->first();
        $this->assertSame(300, (int) $card->balance);
        $this->assertSame(0, $customer->fresh()->points);
        $this->withUnencryptedCookie('lumee_cart', $cart)->get(self::HOST.'/account')->assertOk()->assertSee('Rewards');

        // Cancelling the first order takes back what's left of its points (none left → nothing)
        $this->as()->postJson("/api/admin/orders/{$order->id}/cancel", ['reason' => 'customer', 'restock' => true, 'refund' => false])->assertOk();
        $this->assertSame(0, $customer->fresh()->points);
        $this->assertSame(99, Points::value(Store::find($this->a['store_id']), 99));   // 99 points = 0.99
    }
}
