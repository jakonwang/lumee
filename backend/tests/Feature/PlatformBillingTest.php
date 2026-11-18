<?php

namespace Tests\Feature;

use App\Billing\BillingMailer;
use App\Models\Order;
use App\Models\PlatformFee;
use App\Models\PlatformInvoice;
use App\Models\ProductVariant;
use App\Models\ShippingRate;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PlatformBillingTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private array $a;

    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.payments.test_gateway' => true, 'plans.test_mode' => true, 'services.stripe.secret' => null]);
        Mail::fake();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->a = $this->register('owner@shop-a.test', 'Shop A');
        $id = $this->as()->postJson('/api/admin/products', ['title' => 'Ring', 'price' => 100, 'inventory' => 50, 'status' => 'active'])->json('data.id');
        $this->variantId = ProductVariant::withoutGlobalScope('store')->where('product_id', $id)->value('id');
    }

    private function register(string $email, string $store): array
    {
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => $email, 'password' => 'secret-pass', 'store_name' => $store])->assertCreated();

        return ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
    }

    private function as(?array $m = null, ?int $storeId = null): static
    {
        $m ??= $this->a;
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$m['token'], 'X-Store-Id' => (string) ($storeId ?? $m['store_id']), 'Accept' => 'application/json']);
    }

    private function store(): Store
    {
        return Store::find($this->a['store_id']);
    }

    private function expireTrial(): void
    {
        $this->store()->forceFill(['trial_ends_at' => now()->subMinute()])->save();
    }

    private function checkout(string $gateway = 'manual'): Order
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $cart = $this->post(self::HOST.'/cart/add', ['variant_id' => $this->variantId, 'quantity' => 1])->getCookie('lumee_cart', false)->getValue();
        $rate = ShippingRate::withoutGlobalScope('store')->where('store_id', $this->a['store_id'])->where('price', 0)->first()
            ?? ShippingRate::withoutGlobalScope('store')->where('store_id', $this->a['store_id'])->first();
        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/checkout', [
            'email' => 'buyer@example.com', 'first_name' => 'A', 'last_name' => 'B', 'address1' => '1 St', 'city' => 'X', 'zip' => '1',
            'country' => 'US', 'shipping_rate_id' => $rate->id, 'payment_gateway' => $gateway,
        ])->assertRedirect();

        return Order::withoutGlobalScope('store')->latest('id')->first();
    }

    private function webhook(array $event)
    {
        $payload = json_encode($event);
        $t = time();
        $sig = hash_hmac('sha256', $t.'.'.$payload, 'whsec_test');
        $this->flushHeaders();

        return $this->call('POST', '/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => "t={$t},v1={$sig}", 'CONTENT_TYPE' => 'application/json'], $payload);
    }

    public function test_new_store_is_on_a_14_day_trial(): void
    {
        $res = $this->as()->getJson('/api/admin/billing')->assertOk();
        $res->assertJsonPath('state', 'trialing')->assertJsonPath('trial_days_left', 14)
            ->assertJsonPath('mode', 'test')->assertJsonPath('limits.plan', 'trial')->assertJsonPath('limits.fee_bp', 0)
            ->assertJsonCount(3, 'plans')->assertJsonPath('plans.1.key', 'basic')->assertJsonPath('plans.1.month', '29.00');
        $this->as()->getJson('/api/admin/me')->assertJsonPath('stores.0.billing.state', 'trialing');
    }

    public function test_expired_trial_pauses_admin_and_storefront_until_a_plan_is_chosen(): void
    {
        $order = $this->checkout();
        $this->expireTrial();

        $this->as()->getJson('/api/admin/products')->assertStatus(402)->assertJsonPath('code', 'billing_required')->assertJsonPath('state', 'expired');
        $this->as()->getJson('/api/admin/dashboard')->assertStatus(402);
        $this->as()->getJson('/api/admin/billing')->assertOk()->assertJsonPath('state', 'expired');

        $this->flushHeaders();
        $this->get(self::HOST.'/')->assertStatus(503)->assertSee('temporarily closed');
        $this->get(self::HOST.'/products/ring')->assertStatus(503);
        $this->post(self::HOST.'/cart/add', ['variant_id' => $this->variantId, 'quantity' => 1])->assertStatus(503);
        $this->get(self::HOST."/orders/{$order->token}")->assertOk();   // buyers can still check their orders

        // Owner subscribes (test mode: instant) → everything works again
        $this->as()->postJson('/api/admin/billing/subscribe', ['plan' => 'basic', 'interval' => 'year'])->assertOk()
            ->assertJsonPath('state', 'active')->assertJsonPath('subscription.plan', 'basic')->assertJsonPath('subscription.interval', 'year')
            ->assertJsonPath('invoices.0.amount', '290.00')->assertJsonPath('invoices.0.status', 'paid');
        $this->as()->getJson('/api/admin/products')->assertOk();
        $this->flushHeaders();
        $this->get(self::HOST.'/')->assertOk();
        $this->assertSame('basic', $this->store()->plan);
    }

    public function test_only_the_owner_changes_the_plan_and_limits_apply(): void
    {
        // Staff member (orders only) can view billing but not change it
        $staff = $this->register('staff@example.com', 'Other');
        $this->store()->users()->attach(User::where('email', 'staff@example.com')->value('id'), ['role' => 'staff', 'permissions' => json_encode(['orders'])]);
        $this->as($staff, $this->a['store_id'])->getJson('/api/admin/billing')->assertOk()->assertJsonPath('is_owner', false);
        $this->as($staff, $this->a['store_id'])->postJson('/api/admin/billing/subscribe', ['plan' => 'pro', 'interval' => 'month'])->assertForbidden();

        // Starter allows 1 person: with 2 members, downgrading is refused
        $this->as()->postJson('/api/admin/billing/subscribe', ['plan' => 'starter', 'interval' => 'month'])
            ->assertStatus(422)->assertJsonPath('errors.plan.0', '店铺现有 2 位成员，超过该套餐的上限，请先移除部分员工');
        $this->store()->users()->detach(User::where('email', 'staff@example.com')->value('id'));

        $this->as()->postJson('/api/admin/billing/subscribe', ['plan' => 'starter', 'interval' => 'month'])->assertOk()->assertJsonPath('limits.staff', 1);
        $this->as()->postJson('/api/admin/billing/subscribe', ['plan' => 'starter', 'interval' => 'month'])->assertStatus(422);   // already on it
        $this->as()->postJson('/api/admin/staff/invitations', ['email' => 'new@example.com', 'permissions' => ['orders']])
            ->assertStatus(422)->assertJsonPath('errors.email.0', '当前套餐不支持添加员工，请升级到基础版或以上');
        $this->as()->postJson('/api/admin/domains', ['host' => 'shop.example.com'])
            ->assertStatus(422)->assertJsonPath('errors.host.0', '当前套餐不支持绑定自有域名，请升级到基础版或以上');

        // Upgrade → allowed
        $this->as()->postJson('/api/admin/billing/subscribe', ['plan' => 'pro', 'interval' => 'month'])->assertOk()->assertJsonPath('limits.staff', 15);
        $this->as()->postJson('/api/admin/staff/invitations', ['email' => 'new@example.com', 'permissions' => ['orders']])->assertSuccessful();
    }

    public function test_custom_domain_redirects_to_subdomain_on_a_plan_without_custom_domains(): void
    {
        $this->store()->domains()->create(['host' => 'shop.example.com', 'type' => 'custom', 'is_primary' => false, 'verified_at' => now()]);
        $this->flushHeaders();
        $this->get('http://shop.example.com/collections/all')->assertOk();   // trial includes custom domains

        $this->as()->postJson('/api/admin/billing/subscribe', ['plan' => 'starter', 'interval' => 'month'])->assertOk();
        $this->flushHeaders();
        $this->get('http://shop.example.com/collections/all?page=2')->assertRedirect('http://shop-a.lumee.localhost:8000/collections/all?page=2');
    }

    public function test_transaction_fees_are_recorded_credited_and_billed_monthly(): void
    {
        $trialOrder = $this->checkout();
        $this->as()->postJson("/api/admin/orders/{$trialOrder->id}/mark-paid")->assertOk();
        $this->assertSame(0, PlatformFee::withoutGlobalScope('store')->count());   // no fees during the trial

        $this->as()->postJson('/api/admin/billing/subscribe', ['plan' => 'basic', 'interval' => 'month'])->assertOk();   // 1%
        $order = $this->checkout();
        $this->as()->postJson("/api/admin/orders/{$order->id}/mark-paid")->assertOk();
        $this->checkout('test');   // dev test payments are never charged

        $fee = PlatformFee::withoutGlobalScope('store')->sole();
        $this->assertSame([100, $order->total, (int) round($order->total / 100)], [$fee->rate_bp, $fee->base_amount, $fee->amount]);

        // Refund half → matching credit
        $half = intdiv($order->total, 2);
        $this->as()->postJson("/api/admin/orders/{$order->id}/refunds", ['amount' => number_format($half / 100, 2, '.', '')])->assertOk();
        $this->assertSame(-(int) round($half / 100), PlatformFee::withoutGlobalScope('store')->where('type', 'credit')->value('amount'));
        $expected = (int) round($order->total / 100) - (int) round($half / 100);
        $this->as()->getJson('/api/admin/billing')->assertJsonPath('fees.pending_usd', number_format($expected / 100, 2, '.', ''))->assertJsonPath('fees.rate', 1);

        $this->artisan('lumee:bill-fees')->assertSuccessful();
        $invoice = PlatformInvoice::withoutGlobalScope('store')->where('kind', 'fees')->sole();
        $this->assertSame([$expected, 'paid'], [$invoice->amount, $invoice->status]);   // test-mode subscriber: settled
        $this->assertSame(0, PlatformFee::withoutGlobalScope('store')->whereNull('invoice_id')->count());

        $this->artisan('lumee:bill-fees')->assertSuccessful();   // nothing new to bill
        $this->assertSame(1, PlatformInvoice::withoutGlobalScope('store')->where('kind', 'fees')->count());
    }

    public function test_daily_run_renews_cancels_and_reminds(): void
    {
        $this->as()->postJson('/api/admin/billing/subscribe', ['plan' => 'basic', 'interval' => 'month'])->assertOk();
        Subscription::withoutGlobalScope('store')->update(['current_period_end' => now()->subMinute()]);

        $this->artisan('lumee:billing-daily')->assertSuccessful();
        $sub = Subscription::withoutGlobalScope('store')->sole();
        $this->assertTrue($sub->current_period_end->isFuture());
        $this->assertSame(2, PlatformInvoice::withoutGlobalScope('store')->where('kind', 'subscription')->count());

        // Cancel → keeps working until period end, then ends; the trial is long over → paused
        $this->as()->postJson('/api/admin/billing/cancel')->assertOk()->assertJsonPath('subscription.cancel_at_period_end', true)->assertJsonPath('state', 'active');
        $this->as()->postJson('/api/admin/billing/resume')->assertOk()->assertJsonPath('subscription.cancel_at_period_end', false);
        $this->as()->postJson('/api/admin/billing/cancel')->assertOk();
        $this->expireTrial();
        Subscription::withoutGlobalScope('store')->update(['current_period_end' => now()->subMinute()]);
        $this->artisan('lumee:billing-daily')->assertSuccessful();
        $this->assertSame('canceled', Subscription::withoutGlobalScope('store')->value('status'));
        $this->as()->getJson('/api/admin/billing')->assertJsonPath('state', 'expired');

        // Trial ending in 2 days → one reminder only
        $b = $this->register('b@example.com', 'Shop B');
        Store::find($b['store_id'])->forceFill(['trial_ends_at' => now()->addDays(2)])->save();
        $this->artisan('lumee:billing-daily');
        $this->artisan('lumee:billing-daily');
        Mail::assertQueued(BillingMailer::class, 1);
        Mail::assertQueued(BillingMailer::class, fn ($m) => $m->type === 'trial_ending' && $m->hasTo('b@example.com'));
    }

    public function test_stripe_checkout_and_webhooks_drive_the_subscription(): void
    {
        config(['plans.test_mode' => false, 'services.stripe.secret' => 'sk_test_x', 'services.stripe.webhook_secret' => 'whsec_test']);
        $storeId = $this->a['store_id'];
        $remote = ['id' => 'sub_1', 'status' => 'trialing', 'customer' => 'cus_1', 'cancel_at_period_end' => false,
            'metadata' => ['store_id' => (string) $storeId, 'plan' => 'pro', 'interval' => 'month'],
            'items' => ['data' => [['id' => 'si_1', 'current_period_end' => now()->addDays(14)->timestamp]]]];
        Http::fake([
            'api.stripe.com/v1/products' => Http::response(['id' => 'prod_pro']),
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/cs_1']),
            'api.stripe.com/v1/subscriptions/sub_1' => Http::response($remote),
        ]);

        $this->as()->postJson('/api/admin/billing/subscribe', ['plan' => 'pro', 'interval' => 'month'])
            ->assertOk()->assertJsonPath('redirect_url', 'https://checkout.stripe.com/c/cs_1');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/checkout/sessions')
            && $r['mode'] === 'subscription' && (int) $r['line_items[0][price_data][unit_amount]'] === 7900
            && $r['line_items[0][price_data][recurring][interval]'] === 'month'
            && (int) $r['subscription_data[trial_end]'] === $this->store()->trial_ends_at->timestamp);

        // Forged / unsigned webhooks are rejected
        $this->call('POST', '/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(400);

        $this->webhook(['type' => 'checkout.session.completed', 'data' => ['object' => ['mode' => 'subscription', 'subscription' => 'sub_1', 'customer' => 'cus_1']]])->assertOk();
        $sub = Subscription::withoutGlobalScope('store')->sole();
        $this->assertSame(['pro', 'active', 'stripe', 'cus_1'], [$sub->plan, $sub->status, $sub->provider, $sub->stripe_customer_id]);

        $paid = ['type' => 'invoice.paid', 'data' => ['object' => ['id' => 'in_1', 'subscription' => 'sub_1', 'amount_paid' => 7900,
            'lines' => ['data' => [['amount' => 7900, 'period' => ['start' => now()->timestamp, 'end' => now()->addMonth()->timestamp]]]]]]];
        $this->webhook($paid)->assertOk();
        $this->webhook($paid)->assertOk();   // Stripe retries: recorded once
        $this->assertSame(1, PlatformInvoice::withoutGlobalScope('store')->where('provider_ref', 'in_1')->count());

        // Renewal fails → past due (still open), owner emailed; after the grace period → paused
        $this->webhook(['type' => 'invoice.payment_failed', 'data' => ['object' => ['id' => 'in_2', 'subscription' => 'sub_1']]])->assertOk();
        $this->as()->getJson('/api/admin/billing')->assertJsonPath('state', 'past_due');
        Mail::assertQueued(BillingMailer::class, fn ($m) => $m->type === 'payment_failed');
        $this->expireTrial();
        Subscription::withoutGlobalScope('store')->update(['past_due_since' => now()->subDays(8)]);
        $this->as()->getJson('/api/admin/products')->assertStatus(402);

        // Subscription deleted on Stripe → canceled
        $this->webhook(['type' => 'customer.subscription.deleted', 'data' => ['object' => [...$remote, 'status' => 'canceled']]])->assertOk();
        $this->assertSame('canceled', Subscription::withoutGlobalScope('store')->value('status'));

        // A subscription that isn't ours (no store metadata) is ignored
        $this->webhook(['type' => 'customer.subscription.updated', 'data' => ['object' => ['id' => 'sub_x', 'status' => 'active', 'metadata' => []]]])->assertOk();
        $this->assertSame(1, Subscription::withoutGlobalScope('store')->count());
    }

    public function test_ops_console(): void
    {
        $this->as()->getJson('/api/ops/overview')->assertForbidden();
        $this->artisan('lumee:platform-admin', ['email' => 'owner@shop-a.test'])->assertSuccessful();
        $this->as()->getJson('/api/admin/me')->assertJsonPath('user.is_platform_admin', true);

        $this->as()->postJson('/api/admin/billing/subscribe', ['plan' => 'pro', 'interval' => 'month'])->assertOk();
        $b = $this->register('b@example.com', 'Shop B');

        $this->as()->getJson('/api/ops/overview')->assertOk()
            ->assertJsonPath('stores', 2)->assertJsonPath('states.active', 1)->assertJsonPath('states.trialing', 1)
            ->assertJsonPath('mrr', '79.00')->assertJsonPath('revenue_30d', '79.00');
        $this->as()->getJson('/api/ops/stores?state=trialing')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.name', 'Shop B');
        $this->as()->getJson('/api/ops/stores?q=b@example')->assertJsonPath('data.0.owner.email', 'b@example.com');

        $before = Store::find($b['store_id'])->trial_ends_at;
        $this->as()->postJson("/api/ops/stores/{$b['store_id']}/extend-trial", ['days' => 7])->assertOk();
        $this->assertSame(7, (int) round($before->diffInDays(Store::find($b['store_id'])->trial_ends_at)));

        // Suspend → merchant admin 402 with reason, storefront closed; unsuspend restores
        $this->as()->postJson("/api/ops/stores/{$b['store_id']}/suspend", ['reason' => '涉嫌销售违禁品'])->assertOk()->assertJsonPath('state', 'suspended');
        $this->as($b)->getJson('/api/admin/products')->assertStatus(402)->assertJsonPath('state', 'suspended');
        $this->as($b)->getJson('/api/admin/billing')->assertJsonPath('suspended_reason', '涉嫌销售违禁品');
        $this->flushHeaders();
        $this->get('http://shop-b.lumee.localhost/')->assertStatus(503);
        $this->as()->postJson("/api/ops/stores/{$b['store_id']}/unsuspend")->assertOk()->assertJsonPath('state', 'trialing');

        // Grant a plan for free; the merchant can't change it themselves
        $this->as()->postJson("/api/ops/stores/{$b['store_id']}/grant", ['plan' => 'basic'])->assertStatus(422);   // note required
        $this->as()->postJson("/api/ops/stores/{$b['store_id']}/grant", ['plan' => 'basic', 'note' => '合作伙伴'])
            ->assertOk()->assertJsonPath('state', 'active')->assertJsonPath('plan', 'basic')->assertJsonPath('provider', 'manual');
        $this->as($b)->postJson('/api/admin/billing/subscribe', ['plan' => 'pro', 'interval' => 'month'])->assertStatus(422);

        $this->as()->getJson('/api/ops/invoices')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.store.name', 'Shop A');
    }
}
