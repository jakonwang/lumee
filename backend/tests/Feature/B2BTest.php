<?php

namespace Tests\Feature;

use App\Models\B2bApplication;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Quote;
use App\Models\ShippingRate;
use App\Notifications\B2BMail;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class B2BTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private array $a;

    private int $variant;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.payments.test_gateway' => true]);
        Mail::fake();
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => 'a@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop A'])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
        $this->productId = $this->as()->postJson('/api/admin/products', ['title' => 'Olive Oil', 'price' => 20, 'inventory' => 500, 'status' => 'active'])->json('data.id');
        $this->variant = ProductVariant::withoutGlobalScope('store')->where('product_id', $this->productId)->value('id');
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

    /** A company buyer with an account, signed in on the storefront. */
    private function companyBuyer(array $company = []): Customer
    {
        $c = $this->as()->postJson('/api/admin/companies', ['name' => 'Acme Deli', 'tag' => 'wholesale', ...$company])->assertCreated()->json('data');
        $this->shopper()->post(self::HOST.'/account/register', ['first_name' => 'Bo', 'last_name' => 'Buyer', 'email' => 'bo@acme.test', 'password' => 'correct-horse', 'password_confirmation' => 'correct-horse']);
        $this->as()->postJson("/api/admin/companies/{$c['id']}/members", ['email' => 'bo@acme.test', 'role' => 'admin'])->assertOk()->assertJsonPath('data.customers.0.has_account', true);
        $this->shopper()->post(self::HOST.'/account/login', ['email' => 'bo@acme.test', 'password' => 'correct-horse']);

        return Customer::withoutGlobalScope('store')->where('email', 'bo@acme.test')->first();
    }

    private function priceList(array $prices = [], float $percent = 10): void
    {
        $this->as()->postJson('/api/admin/price-lists', ['name' => 'Wholesale', 'customer_tag' => 'wholesale', 'percent_off' => $percent, 'prices' => $prices])->assertCreated();
    }

    private function checkout(string $cart, array $extra = [])
    {
        $rate = ShippingRate::withoutGlobalScope('store')->where('store_id', $this->a['store_id'])->where('price', 990)->first();

        return $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/checkout', [
            'email' => 'bo@acme.test', 'first_name' => 'Bo', 'last_name' => 'Buyer', 'address1' => '1 St', 'city' => 'Austin',
            'zip' => '78701', 'country' => 'US', 'shipping_rate_id' => $rate->id, 'payment_gateway' => 'test', ...$extra,
        ]);
    }

    public function test_wholesale_application_and_approval(): void
    {
        $this->shopper()->get(self::HOST.'/wholesale')->assertNotFound();
        $this->as()->putJson('/api/admin/b2b/settings', ['hide_prices' => false, 'applications' => true, 'tag' => 'Wholesale', 'terms' => 30])->assertOk()
            ->assertJsonPath('settings.tag', 'Wholesale');
        $this->shopper()->get(self::HOST.'/account/login')->assertSee('Apply for a wholesale account');
        $this->get(self::HOST.'/wholesale')->assertOk();
        $form = ['company_name' => 'Corner Café', 'contact_name' => 'Cleo Park', 'email' => 'Cleo@Corner.test', 'tax_id' => 'EU123', 'message' => 'We sell 50 bottles a month'];
        $this->from(self::HOST.'/wholesale')->post(self::HOST.'/wholesale', $form)->assertSessionHas('status');
        $this->from(self::HOST.'/wholesale')->post(self::HOST.'/wholesale', $form)->assertSessionHasErrors('email');   // already pending
        Mail::assertSent(B2BMail::class, fn ($m) => $m->type === 'application_received');

        $app = $this->as()->getJson('/api/admin/b2b/applications')->assertJsonPath('data.0.company_name', 'Corner Café')->json('data.0');
        $this->as()->getJson('/api/admin/companies')->assertJsonPath('pending_applications', 1);
        $companyId = $this->as()->postJson("/api/admin/b2b/applications/{$app['id']}/approve", ['tax_exempt' => true])->assertCreated()->json('company_id');
        $this->as()->postJson("/api/admin/b2b/applications/{$app['id']}/approve")->assertStatus(422);   // only once

        $company = Company::withoutGlobalScope('store')->find($companyId);
        $this->assertSame('Corner Café', $company->name);
        $this->assertSame(30, $company->payment_terms);   // store default
        $this->assertTrue($company->tax_exempt);
        $this->assertSame('Wholesale', $company->tag);
        $buyer = Customer::withoutGlobalScope('store')->where('email', 'cleo@corner.test')->first();
        $this->assertSame($companyId, $buyer->company_id);
        $this->assertSame('Cleo', $buyer->first_name);
        Mail::assertSent(B2BMail::class, fn ($m) => $m->type === 'application_approved' && $m->data['isNew'] && str_contains($m->data['link'], '/account/reset/'));

        // Rejection emails the applicant
        $this->shopper()->post(self::HOST.'/wholesale', [...$form, 'email' => 'x@y.test', 'company_name' => 'Nope Ltd']);
        $other = B2bApplication::withoutGlobalScope('store')->where('email', 'x@y.test')->first();
        $this->as()->postJson("/api/admin/b2b/applications/{$other->id}/reject", ['reason' => 'Retail only'])->assertOk();
        Mail::assertSent(B2BMail::class, fn ($m) => $m->type === 'application_rejected' && ($m->data['reason'] ?? null) === 'Retail only');
    }

    public function test_company_pricing_quantity_rules_and_volume_tiers(): void
    {
        $this->priceList([['variant_id' => $this->variant, 'price' => null, 'min_qty' => 6, 'step' => 6, 'tiers' => [['min' => 24, 'price' => 15], ['min' => 12, 'price' => 16.5]]]]);
        $this->companyBuyer();   // buyer has no tag of their own: the company's tag gives the price list

        $this->get(self::HOST.'/products/olive-oil')->assertSee('$18.00')->assertSee('Minimum order: 6')->assertSee('Sold in multiples of 6')->assertSee('24+');
        $this->post(self::HOST.'/cart/add', ['variant_id' => $this->variant, 'quantity' => 3])->assertSessionHasErrors('quantity');
        $cart = $this->post(self::HOST.'/cart/add', ['variant_id' => $this->variant, 'quantity' => 6])->getCookie('lumee_cart', false)->getValue();
        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/cart/add', ['variant_id' => $this->variant, 'quantity' => 3])->assertSessionHasErrors('quantity');   // 9: not a multiple
        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/cart/add', ['variant_id' => $this->variant, 'quantity' => 6]);   // 12 → $16.50 tier
        $this->withUnencryptedCookie('lumee_cart', $cart)->get(self::HOST.'/cart')->assertSee('$16.50')->assertSee('$198.00');

        $this->checkout($cart)->assertRedirect();
        $order = Order::withoutGlobalScope('store')->latest('id')->first();
        $this->assertSame(1650 * 12, (int) $order->subtotal);
        $this->assertNotNull($order->company_id);

        // Retail shoppers: regular price, no rules
        $this->post(self::HOST.'/account/logout');
        $this->shopper()->get(self::HOST.'/products/olive-oil')->assertSee('$20.00')->assertDontSee('Minimum order');
        $this->post(self::HOST.'/cart/add', ['variant_id' => $this->variant, 'quantity' => 1])->assertSessionDoesntHaveErrors();
    }

    public function test_b2b_only_products_and_hidden_prices(): void
    {
        $detail = $this->as()->getJson("/api/admin/products/{$this->productId}")->json('data');
        $this->as()->putJson("/api/admin/products/{$this->productId}", [...$detail, 'b2b_only' => true])->assertJsonPath('data.b2b_only', true);
        $retail = $this->as()->postJson('/api/admin/products', ['title' => 'Gift Box', 'price' => 30, 'inventory' => 5, 'status' => 'active'])->json('data.id');
        $retailVariant = ProductVariant::withoutGlobalScope('store')->where('product_id', $retail)->value('id');

        $this->shopper()->get(self::HOST.'/products/olive-oil')->assertNotFound();
        $this->get(self::HOST.'/')->assertDontSee('Olive Oil')->assertSee('Gift Box');
        $this->post(self::HOST.'/cart/add', ['variant_id' => $this->variant, 'quantity' => 1])->assertNotFound();

        // B2B-only store: guests must sign in to see prices
        $this->as()->putJson('/api/admin/b2b/settings', ['hide_prices' => true, 'applications' => false, 'tag' => 'wholesale', 'terms' => 0]);
        $this->shopper()->get(self::HOST.'/products/gift-box')->assertSee('Sign in to see prices and order')->assertSee('Sign in to order');
        $this->post(self::HOST.'/cart/add', ['variant_id' => $retailVariant, 'quantity' => 1])->assertSessionHasErrors('quantity');

        $this->companyBuyer();
        $this->get(self::HOST.'/products/olive-oil')->assertOk()->assertSee('Add to bag');
        $this->get(self::HOST.'/')->assertSee('Olive Oil');
    }

    public function test_payment_terms_po_number_tax_exemption_and_reminders(): void
    {
        $this->as()->putJson('/api/admin/settings/taxes', ['included' => false, 'shipping' => false, 'rates' => [['country' => 'US', 'region' => null, 'name' => 'Sales tax', 'rate' => 10]]])->assertOk();

        // Retail buyer: taxed, no terms
        $this->shopper();
        $cart = $this->post(self::HOST.'/cart/add', ['variant_id' => $this->variant, 'quantity' => 1])->getCookie('lumee_cart', false)->getValue();
        $this->checkout($cart, ['payment_gateway' => 'terms'])->assertSessionHasErrors('payment_gateway');
        $this->checkout($cart)->assertRedirect();
        $this->assertSame(200, (int) Order::withoutGlobalScope('store')->latest('id')->first()->tax_total);

        // Company on Net 30, tax exempt
        $this->companyBuyer(['payment_terms' => 30, 'tax_exempt' => true]);
        $cart = $this->post(self::HOST.'/cart/add', ['variant_id' => $this->variant, 'quantity' => 2])->getCookie('lumee_cart', false)->getValue();
        $this->withUnencryptedCookie('lumee_cart', $cart)->get(self::HOST.'/checkout')->assertSee('Pay on account (Net 30)')->assertSee('tax exempt')->assertSee('PO number');
        $this->checkout($cart, ['payment_gateway' => 'terms', 'po_number' => 'PO-7781'])->assertRedirect();
        $order = Order::withoutGlobalScope('store')->latest('id')->first();
        $this->assertSame('terms', $order->payment_gateway);
        $this->assertSame('pending', $order->financial_status);
        $this->assertSame(0, (int) $order->tax_total);
        $this->assertSame('PO-7781', $order->po_number);
        $this->assertSame(30, (int) round(now()->diffInDays($order->payment_due_at, true)));
        $this->as()->getJson("/api/admin/orders/{$order->id}")->assertJsonPath('data.po_number', 'PO-7781')->assertJsonPath('data.company.name', 'Acme Deli');

        // Not due yet → no reminder; overdue → one reminder only
        Artisan::call('lumee:payment-reminders');
        Mail::assertNotSent(B2BMail::class, fn ($m) => $m->type === 'payment_overdue');
        $order->forceFill(['payment_due_at' => now()->subDay()])->save();
        Artisan::call('lumee:payment-reminders');
        Artisan::call('lumee:payment-reminders');
        Mail::assertSent(B2BMail::class, fn ($m) => $m->type === 'payment_overdue', 1);
        $this->as()->getJson('/api/admin/companies')->assertJsonPath('data.0.overdue', 1)->assertJsonPath('data.0.outstanding', '49.90');   // 2 × 20 + 9.90 shipping, no tax
        $todos = collect($this->as()->getJson('/api/admin/dashboard')->json('todos'))->pluck('count', 'key');
        $this->assertSame(1, $todos['overdue_invoices']);

        // Merchant marks it paid when the money arrives
        $this->as()->postJson("/api/admin/orders/{$order->id}/mark-paid")->assertJsonPath('data.financial_status', 'paid');
    }

    public function test_quote_request_send_accept(): void
    {
        $this->priceList([], 10);
        $buyer = $this->companyBuyer(['payment_terms' => 15, 'locations' => [['name' => 'Warehouse', 'address1' => '9 Dock Rd', 'city' => 'Austin', 'country' => 'US']]]);
        $cart = $this->post(self::HOST.'/cart/add', ['variant_id' => $this->variant, 'quantity' => 100])->getCookie('lumee_cart', false)->getValue();
        $this->withUnencryptedCookie('lumee_cart', $cart)->get(self::HOST.'/cart')->assertSee('Request a quote');
        $this->withUnencryptedCookie('lumee_cart', $cart)->get(self::HOST.'/checkout')->assertSee('Warehouse');
        $res = $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/quotes', ['note' => 'Can you do $14 for 100?']);
        $quote = Quote::withoutGlobalScope('store')->first();
        $res->assertRedirect("/quotes/{$quote->token}");
        $this->assertSame('requested', $quote->status);
        $this->assertSame(1800, $quote->items[0]['list_price']);
        $this->assertSame('9 Dock Rd', $quote->shipping_address['address1']);   // first company location
        Mail::assertSent(B2BMail::class, fn ($m) => $m->type === 'quote_requested');
        $this->get(self::HOST."/quotes/{$quote->token}")->assertSee('Waiting for prices');
        $this->post(self::HOST."/quotes/{$quote->token}/accept")->assertSessionHasErrors('quote');   // not priced yet

        $this->as()->getJson('/api/admin/quotes?status=requested')->assertJsonPath('data.0.name', 'Q1001');
        $this->as()->postJson("/api/admin/quotes/{$quote->id}/send", ['items' => [['variant_id' => $this->variant, 'quantity' => 100, 'price' => 14]],
            'shipping_price' => 25, 'expires_on' => now()->addDays(7)->toDateString(), 'note' => 'Thanks for the bulk order!'])
            ->assertOk()->assertJsonPath('data.status', 'sent')->assertJsonPath('data.items.0.price', '14.00');
        Mail::assertSent(B2BMail::class, fn ($m) => $m->type === 'quote_sent' && $m->hasTo('bo@acme.test'));

        $this->shopper()->get(self::HOST."/quotes/{$quote->token}")->assertSee('$1,425.00')->assertSee('Accept quote');
        $this->post(self::HOST."/quotes/{$quote->token}/accept", ['po_number' => 'PO-1'])->assertRedirect();
        $order = Order::withoutGlobalScope('store')->find($quote->fresh()->order_id);
        $this->assertSame('accepted', $quote->fresh()->status);
        $this->assertSame('quote', $order->source);
        $this->assertSame('terms', $order->payment_gateway);
        $this->assertSame(1400 * 100, (int) $order->subtotal);
        $this->assertSame(2500, (int) $order->shipping_total);
        $this->assertSame('PO-1', $order->po_number);
        $this->assertSame($buyer->company_id, $order->company_id);
        $this->post(self::HOST."/quotes/{$quote->token}/accept")->assertSessionHasErrors('quote');   // only once
        Mail::assertSent(B2BMail::class, fn ($m) => $m->type === 'quote_accepted');
    }
}
