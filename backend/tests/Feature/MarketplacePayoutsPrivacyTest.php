<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PartnerPayout;
use App\Models\PlatformInvoice;
use App\Models\PrivacyRequest;
use App\Models\ShippingRate;
use App\Models\Store;
use App\Models\ThemeListing;
use App\Models\ThemePurchase;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Paid themes, developer payouts, and customer data export / erasure. */
class MarketplacePayoutsPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop.lumee.localhost';

    private array $dev;

    private array $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake(config('lumee.media_disk'));
        Storage::fake('local');
        config(['lumee.payments.test_gateway' => true]);
        $this->dev = $this->register('dev@studio.test', 'Studio');
        $this->shop = $this->register('owner@shop.test', 'Shop');
    }

    private function register(string $email, string $store): array
    {
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'U', 'email' => $email, 'password' => 'secret-pass', 'store_name' => $store])->assertCreated();

        return ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
    }

    private function as(array $who): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders(['Authorization' => 'Bearer '.$who['token'], 'X-Store-Id' => (string) $who['store_id'], 'Accept' => 'application/json']);
    }

    private function paidPlan(int $storeId): void
    {
        app(\App\Billing\Billing::class)->upsert(Store::find($storeId), ['plan' => 'basic', 'interval' => 'month', 'status' => 'active', 'provider' => 'test', 'current_period_end' => now()->addYears(2)]);
    }

    /** A listed theme with a package and a price. */
    private function listedTheme(float $price): int
    {
        $id = $this->as($this->dev)->getJson('/api/admin/themes')->json('data.0.id');
        $res = $this->as($this->dev)->get("/api/admin/themes/{$id}/export");
        $copy = tempnam(sys_get_temp_dir(), 'pt').'.zip';
        copy($res->baseResponse->getFile()->getPathname(), $copy);
        $listing = $this->as($this->dev)->postJson('/api/admin/partner/themes', ['name' => 'Velvet', 'preview_url' => 'https://cdn.test/v.png', 'price' => $price])->assertCreated()->json('data');
        $this->as($this->dev)->post("/api/admin/partner/themes/{$listing['id']}/package", ['file' => new UploadedFile($copy, 'p.zip', 'application/zip', null, true)])->assertOk();
        ThemeListing::whereKey($listing['id'])->update(['status' => 'listed']);

        return $listing['id'];
    }

    public function test_paid_theme_purchase_once_and_revenue_share(): void
    {
        $this->as($this->dev)->postJson('/api/admin/partner/themes', ['name' => 'Odd', 'price' => 33])->assertStatus(422);   // price tiers only
        $id = $this->listedTheme(49);
        $this->as($this->shop)->getJson('/api/admin/themes/market')->assertJsonPath('data.0.price', '49.00')->assertJsonPath('data.0.purchased', false);

        // Must confirm the price, and needs a paid plan (trial stores can't buy)
        $this->as($this->shop)->postJson("/api/admin/themes/market/{$id}/install")->assertStatus(422)->assertJsonValidationErrors('confirm_price');
        $this->as($this->shop)->postJson("/api/admin/themes/market/{$id}/install", ['confirm_price' => '49.00'])->assertStatus(422);
        $this->paidPlan($this->shop['store_id']);
        $this->as($this->shop)->postJson("/api/admin/themes/market/{$id}/install", ['confirm_price' => '29.00'])->assertStatus(422);
        $this->as($this->shop)->postJson("/api/admin/themes/market/{$id}/install", ['confirm_price' => '49.00'])->assertCreated();

        $p = ThemePurchase::sole();
        $this->assertSame(4900, $p->amount);
        $this->assertSame(3920, $p->developer_share);
        $this->assertSame('themes', PlatformInvoice::withoutGlobalScope('store')->find($p->platform_invoice_id)->kind);

        // Reinstall is free (no second purchase); developer sees the sale
        $this->as($this->shop)->postJson("/api/admin/themes/market/{$id}/install")->assertCreated();
        $this->assertSame(1, ThemePurchase::count());
        $this->as($this->shop)->getJson('/api/admin/themes/market')->assertJsonPath('data.0.purchased', true);
        $this->as($this->dev)->getJson('/api/admin/partner/themes')->assertJsonPath('data.0.sales', 1)->assertJsonPath('data.0.earnings', '39.20');
    }

    public function test_monthly_payouts_only_count_paid_invoices(): void
    {
        $id = $this->listedTheme(49);
        $this->paidPlan($this->shop['store_id']);
        $this->as($this->shop)->postJson("/api/admin/themes/market/{$id}/install", ['confirm_price' => '49.00'])->assertCreated();

        // An app bill whose invoice is still open: not paid out yet
        $app = $this->as($this->dev)->postJson('/api/admin/partner/apps', ['name' => 'X', 'app_url' => 'https://x.example.com/', 'redirect_uris' => ['https://x.example.com/cb'], 'scopes' => ['read_products']])->json('data');
        $openInv = PlatformInvoice::withoutGlobalScope('store')->create(['store_id' => $this->shop['store_id'], 'number' => PlatformInvoice::nextNumber(), 'kind' => 'apps', 'description' => 'x', 'amount' => 1000, 'status' => 'open']);
        $charge = \App\Models\AppCharge::create(['app_id' => $app['id'], 'store_id' => $this->shop['store_id'], 'token' => str_repeat('a', 40), 'name' => 'P', 'price' => 1000, 'interval' => 'every_30_days', 'return_url' => 'https://x.example.com/', 'status' => 'active']);
        DB::table('app_charge_bills')->insert(['app_charge_id' => $charge->id, 'app_id' => $app['id'], 'store_id' => $this->shop['store_id'], 'platform_invoice_id' => $openInv->id,
            'amount' => 1000, 'developer_share' => 800, 'period_start' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->as($this->dev)->getJson('/api/admin/partner/payouts')->assertJsonPath('balance.available', '39.20')->assertJsonPath('balance.pending', '8.00');
        $this->as($this->dev)->putJson('/api/admin/partner/payouts/details', ['method' => 'paypal', 'account' => 'not-email'])->assertStatus(422);
        $this->as($this->dev)->putJson('/api/admin/partner/payouts/details', ['method' => 'paypal', 'account' => 'payouts@studio.test'])->assertOk()
            ->assertJsonPath('details.account', 'pa***@studio.test');

        $this->artisan('lumee:partner-payouts', ['--period' => '2026-11'])->expectsOutput('Created 1 payout(s) for 2026-11.');
        $this->artisan('lumee:partner-payouts', ['--period' => '2026-11'])->expectsOutput('Created 0 payout(s) for 2026-11.');
        $payout = PartnerPayout::sole();
        $this->assertSame(3920, $payout->amount);
        $this->assertSame('payouts@studio.test', $payout->details);
        $this->assertNotSame('payouts@studio.test', DB::table('partner_payouts')->value('details'));   // encrypted at rest
        $this->as($this->dev)->getJson('/api/admin/partner/payouts')->assertJsonPath('balance.available', '0.00')->assertJsonPath('payouts.0.status', 'pending');

        // The open invoice is paid later → next month's payout... but below the $25 minimum, it carries over
        $openInv->update(['status' => 'paid', 'paid_at' => now()]);
        $this->artisan('lumee:partner-payouts', ['--period' => '2026-12'])->expectsOutput('Created 0 payout(s) for 2026-12.');

        // Ops pays it
        $this->as($this->shop)->getJson('/api/ops/payouts')->assertForbidden();
        User::where('email', 'owner@shop.test')->update(['is_platform_admin' => true]);
        $this->as($this->shop)->getJson('/api/ops/payouts')->assertJsonPath('pending_total', '39.20')->assertJsonPath('data.0.details', 'payouts@studio.test');
        $this->as($this->shop)->postJson("/api/ops/payouts/{$payout->id}/paid", [])->assertStatus(422);
        $this->as($this->shop)->postJson("/api/ops/payouts/{$payout->id}/paid", ['reference' => 'PP-123'])->assertOk();
        $this->as($this->shop)->postJson("/api/ops/payouts/{$payout->id}/paid", ['reference' => 'PP-124'])->assertStatus(422);
        $this->as($this->dev)->getJson('/api/admin/partner/payouts')->assertJsonPath('balance.paid', '39.20')->assertJsonPath('payouts.0.reference', 'PP-123');
    }

    private function customerWithOrder(): Customer
    {
        $variant = $this->as($this->shop)->postJson('/api/admin/products', ['title' => 'Ring', 'price' => 20, 'inventory' => 50, 'status' => 'active'])->json('data.variants.0.id');
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->post(self::HOST.'/account/register', ['first_name' => 'Gina', 'last_name' => 'Doe', 'email' => 'gina@example.com', 'password' => 'correct-horse', 'password_confirmation' => 'correct-horse']);
        $cart = $this->post(self::HOST.'/cart/add', ['variant_id' => $variant, 'quantity' => 1])->getCookie('lumee_cart', false)->getValue();
        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/checkout', [
            'email' => 'gina@example.com', 'first_name' => 'Gina', 'last_name' => 'Doe', 'address1' => '9 Rue X', 'city' => 'Paris', 'zip' => '75001', 'country' => 'FR',
            'shipping_rate_id' => ShippingRate::withoutGlobalScope('store')->where('store_id', $this->shop['store_id'])->value('id'), 'payment_gateway' => 'test', 'note' => 'Ring the bell',
        ])->assertRedirect();

        return Customer::withoutGlobalScope('store')->where('email', 'gina@example.com')->firstOrFail();
    }

    public function test_customer_export_and_self_service_deletion_request(): void
    {
        $c = $this->customerWithOrder();
        $res = $this->get(self::HOST.'/account/data')->assertOk();
        $data = json_decode($res->streamedContent(), true);
        $this->assertSame('gina@example.com', $data['customer']['email']);
        $this->assertSame('9 Rue X', $data['orders'][0]['shipping_address']['address1']);
        $this->assertSame('Ring', $data['orders'][0]['items'][0]['title']);

        $this->from(self::HOST.'/account')->post(self::HOST.'/account/delete', ['current_password' => 'wrong'])->assertSessionHasErrors('delete_password');
        $this->from(self::HOST.'/account')->post(self::HOST.'/account/delete', ['current_password' => 'correct-horse'])->assertSessionHas('status');
        $this->post(self::HOST.'/account/delete', ['current_password' => 'correct-horse']);   // no duplicate
        $this->assertSame(1, PrivacyRequest::withoutGlobalScope('store')->where('type', 'erase')->count());

        // The order isn't shipped yet → erasure waits
        $list = $this->as($this->shop)->getJson('/api/admin/privacy-requests')->assertOk()->assertJsonPath('pending', 1);
        $req = collect($list->json('data'))->firstWhere('type', 'erase');
        $this->assertNotNull($req['blocker']);
        $this->as($this->shop)->postJson("/api/admin/privacy-requests/{$req['id']}/complete")->assertStatus(422);

        $order = Order::withoutGlobalScope('store')->sole();
        $this->as($this->shop)->postJson("/api/admin/orders/{$order->id}/fulfill", ['tracking_number' => 'T1'])->assertOk();
        $this->as($this->shop)->postJson("/api/admin/privacy-requests/{$req['id']}/complete")->assertOk();

        $order->refresh();
        $this->assertSame("erased-order-{$order->id}@privacy.invalid", $order->email);
        $this->assertSame(['country' => 'FR'], $order->shipping_address);
        $this->assertNull($order->note);
        $this->assertSame(2000, (int) $order->subtotal);   // sales record kept
        $this->assertNull(Customer::withoutGlobalScope('store')->find($c->id));
        $done = PrivacyRequest::withoutGlobalScope('store')->find($req['id']);
        $this->assertSame('completed', $done->status);
        $this->assertStringStartsWith('erased:', $done->email);
    }

    public function test_merchant_export_and_erase_from_customer_page(): void
    {
        $c = $this->customerWithOrder();
        $this->as($this->shop)->get("/api/admin/customers/{$c->id}/data")->assertOk()->assertHeader('content-type', 'application/json');
        $this->as($this->dev)->get("/api/admin/customers/{$c->id}/data")->assertNotFound();   // other store

        Order::withoutGlobalScope('store')->update(['fulfillment_status' => 'fulfilled']);
        $this->as($this->shop)->postJson("/api/admin/customers/{$c->id}/erase", ['confirm' => 'nope'])->assertStatus(422);
        $this->as($this->shop)->postJson("/api/admin/customers/{$c->id}/erase", ['confirm' => 'gina@example.com'])->assertOk();
        $this->assertNull(Customer::withoutGlobalScope('store')->find($c->id));
        $this->assertSame(0, Order::withoutGlobalScope('store')->where('email', 'gina@example.com')->count());
        $this->as($this->shop)->getJson('/api/admin/privacy-requests')->assertJsonPath('data.0.status', 'completed');
    }
}
