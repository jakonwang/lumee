<?php

namespace Tests\Feature;

use App\Marketing\Flows;
use App\Models\App;
use App\Models\AppInstallation;
use App\Models\Customer;
use App\Models\EmailFlowEnrollment;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\ShippingRate;
use App\Models\StockAlert;
use App\Models\Store;
use App\Notifications\CampaignMail;
use App\Notifications\StockAlertMail;
use App\Services\Inventory;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Back-in-stock alerts, birthday + browse-abandon flows, Stripe Tax, Razorpay and app reviews. */
class GrowthBatchTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private array $a;

    private int $productId;

    private int $variant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.payments.test_gateway' => true]);
        Mail::fake();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->a = $this->register('owner@shop-a.test', 'Shop A');
        Store::whereKey($this->a['store_id'])->update(['trial_ends_at' => now()->addYears(2)]);
        $p = $this->as()->postJson('/api/admin/products', ['title' => 'Olive Oil', 'price' => 20, 'inventory' => 0, 'status' => 'active'])->json('data');
        $this->productId = $p['id'];
        $this->variant = $p['variants'][0]['id'];
    }

    private function register(string $email, string $store): array
    {
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'U', 'email' => $email, 'password' => 'secret-pass', 'store_name' => $store])->assertCreated();

        return ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
    }

    private function as(?array $who = null): static
    {
        $who ??= $this->a;
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$who['token'], 'X-Store-Id' => (string) $who['store_id'], 'Accept' => 'application/json']);
    }

    private function shopper(): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this;
    }

    private function cart(int $qty = 1): string
    {
        return $this->shopper()->post(self::HOST.'/cart/add', ['variant_id' => $this->variant, 'quantity' => $qty])->getCookie('lumee_cart', false)->getValue();
    }

    private function checkout(string $cart, array $extra = [])
    {
        return $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/checkout', [
            'email' => 'bo@buyer.test', 'first_name' => 'Bo', 'last_name' => 'Buyer', 'address1' => '100 Congress Ave', 'city' => 'Austin',
            'province' => 'TX', 'zip' => '78701', 'country' => 'US', 'shipping_rate_id' => ShippingRate::withoutGlobalScope('store')->where('store_id', $this->a['store_id'])->value('id'),
            'payment_gateway' => 'test', ...$extra,
        ]);
    }

    private function enableFlow(string $type, array $extra = []): void
    {
        $id = collect($this->as()->getJson('/api/admin/flows')->assertOk()->json('data'))->firstWhere('type', $type)['id'];
        $flow = $this->as()->getJson("/api/admin/flows/{$id}")->json('data');
        $this->as()->putJson("/api/admin/flows/{$id}", [
            'enabled' => true, 'stop_on_order' => $flow['stop_on_order'], 'win_back_days' => $flow['win_back_days'], 'steps' => $flow['steps'], ...$extra,
        ])->assertOk();
    }

    public function test_back_in_stock_alerts(): void
    {
        $this->shopper()->get(self::HOST.'/products/olive-oil')->assertOk()->assertSee('Sold out — get an email when it’s back', false);
        $this->from(self::HOST.'/products/olive-oil')->post(self::HOST.'/products/olive-oil/notify', ['variant_id' => $this->variant, 'email' => 'Fan@Example.com'])
            ->assertSessionHas('notify_status');
        $this->post(self::HOST.'/products/olive-oil/notify', ['variant_id' => $this->variant, 'email' => 'fan@example.com']);   // no duplicate
        $this->post(self::HOST.'/products/olive-oil/notify', ['variant_id' => $this->variant, 'email' => 'not-an-email'])->assertSessionHasErrors('email');
        $this->assertSame(1, StockAlert::withoutGlobalScope('store')->count());
        $this->as()->getJson("/api/admin/products/{$this->productId}")->assertJsonPath('data.stock_alerts', 1);

        // Restock (from the stock ledger): one email, alert closed
        Inventory::change($this->variant, 5, 'restock');
        Mail::assertSent(StockAlertMail::class, fn ($m) => $m->hasTo('fan@example.com') && str_contains($m->render(), '?variant='.$this->variant));
        $this->assertNotNull(StockAlert::withoutGlobalScope('store')->sole()->notified_at);
        Inventory::change($this->variant, 5, 'restock');   // still in stock: nothing new
        Mail::assertSent(StockAlertMail::class, 1);

        // Sold out again → sign up again → restock from the editor (model hook)
        $v = ProductVariant::withoutGlobalScope('store')->find($this->variant);
        $v->forceFill(['inventory_quantity' => 0])->save();
        $this->post(self::HOST.'/products/olive-oil/notify', ['variant_id' => $this->variant, 'email' => 'fan@example.com']);
        $v->refresh()->forceFill(['inventory_quantity' => 3])->save();
        Mail::assertSent(StockAlertMail::class, 2);
        // In stock: no sign-up needed
        $this->post(self::HOST.'/products/olive-oil/notify', ['variant_id' => $this->variant, 'email' => 'late@example.com'])->assertSessionHas('status');
        $this->assertSame(1, StockAlert::withoutGlobalScope('store')->count());
    }

    public function test_birthday_flow_once_a_year(): void
    {
        $this->enableFlow('birthday');
        $this->shopper()->postJson(self::HOST.'/newsletter', ['email' => 'bday@example.com'])->assertOk();
        $this->shopper()->postJson(self::HOST.'/newsletter', ['email' => 'other@example.com'])->assertOk();
        Customer::withoutGlobalScope('store')->where('email', 'bday@example.com')->update(['birthday' => now()->subYears(30)->toDateString()]);
        Customer::withoutGlobalScope('store')->where('email', 'other@example.com')->update(['birthday' => now()->subYears(30)->addDays(3)->toDateString()]);

        $flows = app(Flows::class);
        $this->assertSame(1, $flows->scanBirthdays());
        $this->assertSame(0, $flows->scanBirthdays());   // already this year
        $this->artisan('lumee:run-flows');
        Mail::assertSent(CampaignMail::class, fn ($m) => $m->hasTo('bday@example.com') && $m->campaign->subject === 'Happy birthday from Shop A!');
        Mail::assertNotSent(CampaignMail::class, fn ($m) => $m->hasTo('other@example.com'));

        // Admin can set it; storefront rejects a future date
        $id = Customer::withoutGlobalScope('store')->where('email', 'other@example.com')->value('id');
        $this->as()->patchJson("/api/admin/customers/{$id}", ['birthday' => '1990-02-28'])->assertOk()->assertJsonPath('data.birthday', '1990-02-28');
        $this->as()->patchJson("/api/admin/customers/{$id}", ['birthday' => now()->addDay()->toDateString()])->assertStatus(422);
    }

    public function test_browse_abandonment_flow(): void
    {
        ProductVariant::withoutGlobalScope('store')->whereKey($this->variant)->update(['inventory_quantity' => 10]);
        $this->enableFlow('browse_abandon', ['browse_hours' => 4]);
        $this->shopper()->post(self::HOST.'/account/register', ['first_name' => 'Vi', 'last_name' => 'Ewer', 'email' => 'vi@example.com', 'password' => 'correct-horse', 'password_confirmation' => 'correct-horse']);
        $c = Customer::withoutGlobalScope('store')->where('email', 'vi@example.com')->firstOrFail();
        $c->forceFill(['accepts_marketing' => true])->save();
        $this->get(self::HOST.'/products/olive-oil')->assertOk();
        $this->assertSame($this->productId, (int) $c->fresh()->last_viewed_product_id);

        $flows = app(Flows::class);
        $this->assertSame(0, $flows->scanBrowseAbandon());   // too soon
        $this->travel(5)->hours();
        $this->assertSame(1, $flows->scanBrowseAbandon());
        $this->assertSame(0, $flows->scanBrowseAbandon());   // once a week
        $this->artisan('lumee:run-flows');
        Mail::assertSent(CampaignMail::class, fn ($m) => $m->hasTo('vi@example.com') && $m->campaign->subject === 'Still thinking it over?' && str_contains($m->render(), 'Olive Oil'));
        $this->assertSame(1, EmailFlowEnrollment::withoutGlobalScope('store')->count());
        $this->as()->getJson('/api/admin/flows/'.EmailFlowEnrollment::withoutGlobalScope('store')->value('email_flow_id'))->assertJsonPath('data.browse_hours', 4);
    }

    public function test_stripe_tax_for_us_addresses(): void
    {
        ProductVariant::withoutGlobalScope('store')->whereKey($this->variant)->update(['inventory_quantity' => 10]);
        config(['services.stripe.secret' => 'sk_test_x']);
        $this->as()->putJson('/api/admin/settings/taxes', ['included' => false, 'shipping' => false, 'stripe_tax' => true, 'rates' => [['country' => 'US', 'region' => 'TX', 'name' => 'TX', 'rate' => 6.25]]])
            ->assertOk()->assertJsonPath('settings.stripe_tax', true)->assertJsonPath('stripe_tax_available', true);
        $stripeUp = true;
        Http::fake(['api.stripe.com/v1/tax/calculations' => function () use (&$stripeUp) {
            return $stripeUp ? Http::response(['id' => 'taxcalc_1', 'tax_amount_exclusive' => 412]) : Http::response(['error' => ['message' => 'nope']], 500);
        }]);

        $cart = $this->cart(2);
        $this->withCredentials()->withUnencryptedCookie('lumee_cart', $cart)->getJson(self::HOST.'/checkout/shipping-rates?country=US&province=TX&zip=78701')
            ->assertOk()->assertJsonPath('tax.name', 'Sales tax');
        $this->checkout($cart)->assertRedirect();
        $order = Order::withoutGlobalScope('store')->latest('id')->firstOrFail();
        $this->assertSame(412, (int) $order->tax_total);
        $this->assertSame((int) $order->subtotal + (int) $order->shipping_total + 412, (int) $order->total);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'tax/calculations') && $r['customer_details[address][postal_code]'] === '78701'
            && $r['customer_details[address][state]'] === 'TX' && (int) $r['line_items[0][amount]'] === 4000);

        // Stripe Tax down → the store's own state rate
        $stripeUp = false;
        $this->checkout($this->cart(1), ['zip' => '78702'])->assertRedirect();
        $this->assertSame(125, (int) Order::withoutGlobalScope('store')->latest('id')->first()->tax_total);   // 6.25% of $20
    }

    public function test_razorpay_upi_payment_and_refund(): void
    {
        ProductVariant::withoutGlobalScope('store')->whereKey($this->variant)->update(['inventory_quantity' => 10]);
        config(['services.razorpay.key_id' => 'rzp_test_x', 'services.razorpay.key_secret' => 'rzp_secret']);
        $this->as()->getJson('/api/admin/settings/payments')->assertJsonPath('available.razorpay', true)->assertJsonPath('razorpay_currency_ok', false);
        $this->shopper()->get(self::HOST.'/checkout')->assertDontSee('Razorpay');   // USD store

        Store::whereKey($this->a['store_id'])->update(['currency' => 'INR']);
        $link = ['id' => 'plink_1', 'status' => 'created', 'amount_paid' => 0, 'currency' => 'INR', 'payments' => [['payment_id' => 'pay_1', 'status' => 'captured']]];
        Http::fake([
            'api.razorpay.com/v1/payment_links/plink_1' => function () use (&$link) {
                return Http::response($link);
            },
            'api.razorpay.com/v1/payment_links' => Http::response(['id' => 'plink_1', 'short_url' => 'https://rzp.io/i/abc']),
            'api.razorpay.com/v1/payments/pay_1/refund' => Http::response(['id' => 'rfnd_1']),
        ]);
        $cart = $this->cart(1);
        $this->withUnencryptedCookie('lumee_cart', $cart)->get(self::HOST.'/checkout')->assertSee('UPI, cards &amp; netbanking (Razorpay)', false);
        $this->checkout($cart, ['payment_gateway' => 'razorpay'])->assertRedirect('https://rzp.io/i/abc');
        $order = Order::withoutGlobalScope('store')->latest('id')->firstOrFail();
        $this->assertSame('plink_1', $order->payment_reference);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.razorpay.com/v1/payment_links' && $r['amount'] === (int) $order->total && $r['currency'] === 'INR');

        $q = ['order' => $order->token, 'razorpay_payment_id' => 'pay_1', 'razorpay_payment_link_id' => 'plink_1',
            'razorpay_payment_link_reference_id' => $order->token, 'razorpay_payment_link_status' => 'paid'];
        $sign = fn (array $q) => hash_hmac('sha256', "plink_1|{$order->token}|paid|pay_1", 'rzp_secret');
        // Forged signature: ignored
        $this->get(self::HOST.'/checkout/razorpay/return?'.http_build_query([...$q, 'razorpay_signature' => 'bad']))->assertRedirect("/orders/{$order->token}");
        $this->assertSame('pending', $order->fresh()->financial_status);
        // Signed, but Razorpay says not paid yet → still pending
        $this->get(self::HOST.'/checkout/razorpay/return?'.http_build_query([...$q, 'razorpay_signature' => $sign($q)]));
        $this->assertSame('pending', $order->fresh()->financial_status);
        // Paid, but for the wrong amount → still pending
        $link['status'] = 'paid';
        $link['amount_paid'] = 100;
        $this->get(self::HOST.'/checkout/razorpay/return?'.http_build_query([...$q, 'razorpay_signature' => $sign($q)]));
        $this->assertSame('pending', $order->fresh()->financial_status);

        $link['amount_paid'] = (int) $order->total;
        $this->get(self::HOST.'/checkout/razorpay/return?'.http_build_query([...$q, 'razorpay_signature' => $sign($q)]))->assertRedirect("/orders/{$order->token}?paid=1");
        $order->refresh();
        $this->assertSame('paid', $order->financial_status);
        $this->assertSame('pay_1', $order->payment_reference);

        $this->as()->postJson("/api/admin/orders/{$order->id}/refunds", ['amount' => 5, 'reason' => 'Damaged'])->assertOk();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/payments/pay_1/refund') && $r['amount'] === 500);
    }

    public function test_pix_is_offered_for_brl_stores(): void
    {
        Store::whereKey($this->a['store_id'])->update(['currency' => 'BRL']);
        $methods = collect($this->as()->getJson('/api/admin/settings/payments')->json('stripe_methods'))->keyBy('key');
        $this->assertTrue($methods['pix']['supported']);
        $this->assertFalse($methods['klarna']['supported']);
    }

    public function test_app_reviews_and_developer_replies(): void
    {
        $dev = $this->register('dev@studio.test', 'Studio');
        $app = $this->as($dev)->postJson('/api/admin/partner/apps', [
            'name' => 'Stock Sync', 'app_url' => 'https://app.example.com/', 'redirect_uris' => ['https://app.example.com/cb'], 'scopes' => ['read_products'],
        ])->assertCreated()->json('data');
        App::whereKey($app['id'])->update(['status' => 'listed']);

        // Not installed: can read, can't review
        $this->as()->getJson("/api/admin/apps/{$app['id']}/reviews")->assertOk()->assertJsonPath('can_review', false);
        $this->as()->putJson("/api/admin/apps/{$app['id']}/review", ['rating' => 5])->assertStatus(422);

        AppInstallation::withoutGlobalScope('store')->forceCreate(['store_id' => $this->a['store_id'], 'app_id' => $app['id'], 'scopes' => ['read_products'], 'installed_at' => now()]);
        AppInstallation::withoutGlobalScope('store')->forceCreate(['store_id' => $dev['store_id'], 'app_id' => $app['id'], 'scopes' => ['read_products'], 'installed_at' => now()]);
        $this->as()->putJson("/api/admin/apps/{$app['id']}/review", ['rating' => 6])->assertStatus(422);
        $this->as()->putJson("/api/admin/apps/{$app['id']}/review", ['rating' => 5, 'body' => 'Great'])->assertOk()->assertJsonPath('app.rating', 5);
        $this->as()->putJson("/api/admin/apps/{$app['id']}/review", ['rating' => 4, 'body' => 'Good'])->assertOk();   // one per store: updated
        $this->as($dev)->putJson("/api/admin/apps/{$app['id']}/review", ['rating' => 1])->assertOk()->assertJsonPath('app.rating', 2.5)->assertJsonPath('app.rating_count', 2);
        $this->as()->getJson('/api/admin/apps')->assertJsonPath('store.0.rating', 2.5)->assertJsonPath('store.0.rating_count', 2);

        // Developer replies from the partner portal; others can't
        $reviews = $this->as($dev)->getJson("/api/admin/partner/apps/{$app['id']}/reviews")->assertOk()->json('data');
        $mine = collect($reviews)->firstWhere('rating', 4);
        $this->as()->putJson("/api/admin/partner/apps/{$app['id']}/reviews/{$mine['id']}/reply", ['reply' => 'Hi'])->assertNotFound();
        $this->as($dev)->putJson("/api/admin/partner/apps/{$app['id']}/reviews/{$mine['id']}/reply", ['reply' => 'Thanks!'])->assertOk();
        $this->as()->getJson("/api/admin/apps/{$app['id']}/reviews")->assertJsonPath('mine.developer_reply', 'Thanks!')->assertJsonPath('can_review', true)
            ->assertJsonPath('distribution.1.count', 1);

        $this->as()->deleteJson("/api/admin/apps/{$app['id']}/review")->assertOk();
        $this->assertSame(1, (int) App::find($app['id'])->rating_count);
        $this->assertEquals(1.0, (float) App::find($app['id'])->rating_avg);
    }
}
