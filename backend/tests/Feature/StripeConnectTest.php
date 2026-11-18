<?php

namespace Tests\Feature;

use App\Billing\FeeLedger;
use App\Models\Order;
use App\Models\PlatformFee;
use App\Models\ShippingRate;
use App\Models\Store;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StripeConnectTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private array $a;

    private int $variantId;

    /** What GET /v1/accounts/acct_1 returns (the account as Stripe sees it). */
    private array $account = ['id' => 'acct_1', 'charges_enabled' => false, 'payouts_enabled' => false, 'details_submitted' => false,
        'country' => 'HK', 'default_currency' => 'hkd', 'email' => 'owner@shop-a.test', 'requirements' => ['currently_due' => ['external_account', 'tos_acceptance.date']]];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.stripe' => ['secret' => 'sk_test_x', 'webhook_secret' => 'whsec_p', 'connect' => true, 'connect_webhook_secret' => 'whsec_c'],
            'plans.test_mode' => true,
            'lumee.admin_url' => 'http://admin.test',
        ]);
        Mail::fake();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $res = $this->postJson('/api/admin/auth/register', [
            'name' => 'M', 'email' => 'owner@shop-a.test', 'password' => 'secret-pass', 'store_name' => 'Shop A',
        ])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
        $this->variantId = $this->as()->postJson('/api/admin/products', [
            'title' => 'Silk Scarf', 'price' => 80, 'inventory' => 20, 'status' => 'active',
        ])->assertCreated()->json('data.variants.0.id');

        Http::fake(function (HttpRequest $r) {
            $path = parse_url($r->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/v1/accounts' => Http::response(['id' => 'acct_1', 'charges_enabled' => false, 'details_submitted' => false, 'country' => $r['country']]),
                $path === '/v1/account_links' => Http::response(['url' => 'https://connect.stripe.com/setup/e/acct_1/xyz']),
                $path === '/v1/accounts/acct_1' => Http::response($this->account),
                $path === '/v1/accounts/acct_1/login_links' => Http::response(['url' => 'https://connect.stripe.com/express/abc']),
                $path === '/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1']),
                $path === '/v1/refunds' => Http::response(['id' => 're_1']),
                $path === '/v1/charges/ch_1' => Http::response(['id' => 'ch_1', 'transfer' => 'tr_1']),
                $path === '/v1/transfers/tr_1/reversals' => Http::response(['id' => 'trr_1']),
                $path === '/v1/transfers' => Http::response(['id' => 'tr_back']),
                default => Http::response(['error' => ['message' => 'unexpected '.$path]], 500),
            };
        });
    }

    private function as(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->a['token'], 'X-Store-Id' => (string) $this->a['store_id'], 'Accept' => 'application/json']);
    }

    private function store(): Store
    {
        return Store::find($this->a['store_id']);
    }

    private function activate(): void
    {
        $this->account = [...$this->account, 'charges_enabled' => true, 'payouts_enabled' => true, 'details_submitted' => true, 'requirements' => ['currently_due' => []]];
        $this->as()->postJson('/api/admin/settings/payments/stripe/connect', ['country' => 'HK'])->assertOk();
        $this->as()->postJson('/api/admin/settings/payments/stripe/refresh')->assertOk()->assertJsonPath('stripe_connect.status', 'active');
    }

    private function cart(): string
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->post(self::HOST.'/cart/add', ['variant_id' => $this->variantId, 'quantity' => 1])->getCookie('lumee_cart', false)->getValue();
    }

    private function checkout(string $gateway = 'stripe'): \Illuminate\Testing\TestResponse
    {
        $cart = $this->cart();

        return $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/checkout', [
            'email' => 'buyer@example.com', 'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'address1' => '1 Main St', 'city' => 'Austin', 'zip' => '73301', 'country' => 'US',
            'shipping_rate_id' => ShippingRate::withoutGlobalScope('store')->where('price', '>', 0)->value('id'), 'payment_gateway' => $gateway,
        ]);
    }

    private function webhook(array $event, string $secret): \Illuminate\Testing\TestResponse
    {
        $payload = json_encode($event);
        $t = time();
        $sig = hash_hmac('sha256', $t.'.'.$payload, $secret);

        return $this->call('POST', '/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => "t={$t},v1={$sig}", 'CONTENT_TYPE' => 'application/json'], $payload);
    }

    public function test_onboarding_creates_one_express_account_and_card_payments_wait_for_stripe(): void
    {
        $this->as()->getJson('/api/admin/settings/payments')->assertOk()
            ->assertJsonPath('stripe_connect.enabled', true)->assertJsonPath('stripe_connect.status', 'not_connected');
        $cart = $this->cart();
        $this->withUnencryptedCookie('lumee_cart', $cart)->get(self::HOST.'/checkout')->assertOk()->assertDontSee('value="stripe"', false);

        // Card payments alone can't be the only method until the account works
        $this->as()->putJson('/api/admin/settings/payments', ['stripe' => ['enabled' => true], 'manual' => ['enabled' => false]])->assertStatus(422);

        $this->as()->postJson('/api/admin/settings/payments/stripe/connect', [])->assertStatus(422);
        $this->as()->postJson('/api/admin/settings/payments/stripe/connect', ['country' => 'CN'])->assertStatus(422);   // not a Stripe country
        $this->as()->postJson('/api/admin/settings/payments/stripe/connect', ['country' => 'HK'])
            ->assertOk()->assertJsonPath('url', 'https://connect.stripe.com/setup/e/acct_1/xyz');
        $this->assertSame('acct_1', $this->store()->stripe_account_id);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.stripe.com/v1/accounts' && $r['type'] === 'express' && $r['country'] === 'HK'
            && $r['capabilities[card_payments][requested]'] === 'true' && $r['metadata[store_id]'] == $this->a['store_id'] && $r->hasHeader('Idempotency-Key'));
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v1/account_links') && $r['account'] === 'acct_1'
            && $r['return_url'] === 'http://admin.test/settings/payments?stripe=return' && $r['refresh_url'] === 'http://admin.test/settings/payments?stripe=refresh');

        // Continuing onboarding reuses the account
        $this->as()->postJson('/api/admin/settings/payments/stripe/connect')->assertOk();
        $this->assertCount(1, Http::recorded(fn (HttpRequest $r) => $r->url() === 'https://api.stripe.com/v1/accounts'));

        $this->as()->postJson('/api/admin/settings/payments/stripe/refresh')->assertOk()
            ->assertJsonPath('stripe_connect.status', 'onboarding')->assertJsonPath('stripe_connect.currently_due', 2);
        $this->as()->postJson('/api/admin/settings/payments/stripe/dashboard')->assertStatus(422);   // nothing to see before onboarding

        $this->account = [...$this->account, 'details_submitted' => true, 'requirements' => ['currently_due' => [], 'disabled_reason' => 'requirements.pending_verification']];
        $this->as()->postJson('/api/admin/settings/payments/stripe/refresh')->assertJsonPath('stripe_connect.status', 'pending');

        $this->activate();
        $this->as()->getJson('/api/admin/settings/payments')->assertJsonPath('stripe_connect.country', 'HK')->assertJsonPath('stripe_connect.default_currency', 'HKD');
        $this->withUnencryptedCookie('lumee_cart', $cart)->get(self::HOST.'/checkout')->assertOk()->assertSee('value="stripe"', false);
        $this->as()->putJson('/api/admin/settings/payments', ['stripe' => ['enabled' => true], 'manual' => ['enabled' => false]])->assertOk();
        $this->as()->postJson('/api/admin/settings/payments/stripe/dashboard')->assertOk()->assertJsonPath('url', 'https://connect.stripe.com/express/abc');
    }

    public function test_destination_charge_takes_the_fee_upfront_and_refunds_reverse_it(): void
    {
        $this->activate();
        $this->as()->postJson('/api/admin/billing/subscribe', ['plan' => 'basic', 'interval' => 'month'])->assertOk();   // 1% fee

        $this->checkout()->assertRedirect('https://checkout.stripe.com/c/pay/cs_1');
        $order = Order::withoutGlobalScope('store')->latest('id')->firstOrFail();
        $fee = (int) round($order->total / 100);
        $this->assertSame(['acct_1', $fee], [$order->stripe_account, $order->application_fee]);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v1/checkout/sessions')
            && $r['payment_intent_data[transfer_data][destination]'] === 'acct_1'
            && $r['payment_intent_data[on_behalf_of]'] === 'acct_1'
            && (int) $r['payment_intent_data[application_fee_amount]'] === $fee);

        $this->webhook(['type' => 'checkout.session.completed', 'data' => ['object' => [
            'id' => 'cs_1', 'mode' => 'payment', 'payment_status' => 'paid', 'payment_intent' => 'pi_1', 'metadata' => ['order_id' => $order->id],
        ]]], 'whsec_p')->assertOk();
        $order->refresh();
        $this->assertSame('paid', $order->financial_status);

        // Fee recorded as already collected → never on the monthly bill
        $row = PlatformFee::withoutGlobalScope('store')->sole();
        $this->assertSame([$fee, 100], [$row->amount, $row->rate_bp]);
        $this->assertNotNull($row->collected_at);
        $this->assertSame([], app(FeeLedger::class)->pending($this->store())['ids']);

        $this->as()->postJson("/api/admin/orders/{$order->id}/refunds", ['amount' => '20'])->assertOk()->assertJsonPath('data.refunds.0.reference', 're_1');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v1/refunds') && $r['payment_intent'] === 'pi_1'
            && $r['reverse_transfer'] === 'true' && $r['refund_application_fee'] === 'true' && (int) $r['amount'] === 2000);
        $credit = PlatformFee::withoutGlobalScope('store')->where('type', 'credit')->sole();
        $this->assertNotNull($credit->collected_at);
        $this->assertSame([], app(FeeLedger::class)->pending($this->store())['ids']);
    }

    public function test_trial_store_pays_no_application_fee(): void
    {
        $this->activate();
        $this->checkout()->assertRedirect();
        $order = Order::withoutGlobalScope('store')->latest('id')->firstOrFail();
        $this->assertSame(0, $order->application_fee);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v1/checkout/sessions')
            && ! isset($r['payment_intent_data[application_fee_amount]']) && $r['payment_intent_data[transfer_data][destination]'] === 'acct_1');
    }

    public function test_connect_webhooks_update_and_deauthorize_accounts(): void
    {
        $this->as()->postJson('/api/admin/settings/payments/stripe/connect', ['country' => 'HK'])->assertOk();
        $updated = ['type' => 'account.updated', 'account' => 'acct_1', 'data' => ['object' => [
            ...$this->account, 'charges_enabled' => true, 'payouts_enabled' => true, 'details_submitted' => true, 'requirements' => ['currently_due' => []],
        ]]];

        $this->webhook($updated, 'whsec_wrong')->assertStatus(400);
        $this->assertFalse($this->store()->stripe_account['charges_enabled']);

        $this->webhook($updated, 'whsec_c')->assertOk();   // Connect endpoint secret
        $this->assertTrue($this->store()->stripe_account['charges_enabled']);
        $this->as()->getJson('/api/admin/settings/payments')->assertJsonPath('stripe_connect.status', 'active');

        $this->webhook(['type' => 'account.application.deauthorized', 'account' => 'acct_1', 'data' => ['object' => []]], 'whsec_c')->assertOk();
        $this->assertNull($this->store()->stripe_account_id);
    }

    public function test_disconnect_and_connect_off(): void
    {
        $this->activate();
        $this->as()->deleteJson('/api/admin/settings/payments/stripe')->assertOk()->assertJsonPath('stripe_connect.status', 'not_connected');
        $this->assertNull($this->store()->stripe_account_id);
        $this->checkout()->assertSessionHasErrors('payment_gateway');

        config(['services.stripe.connect' => false]);
        $this->as()->postJson('/api/admin/settings/payments/stripe/connect', ['country' => 'HK'])->assertStatus(422);
        $this->as()->getJson('/api/admin/settings/payments')->assertJsonPath('stripe_connect.enabled', false);
        $this->checkout()->assertRedirect('https://checkout.stripe.com/c/pay/cs_1');   // legacy: platform account
        $this->assertNull(Order::withoutGlobalScope('store')->latest('id')->value('stripe_account'));
    }

    public function test_dispute_takes_money_back_from_the_store_and_returns_it_if_won(): void
    {
        $this->activate();
        $this->as()->postJson('/api/admin/billing/subscribe', ['plan' => 'basic', 'interval' => 'month'])->assertOk();
        $this->checkout()->assertRedirect();
        $order = Order::withoutGlobalScope('store')->latest('id')->firstOrFail();
        $order->markPaid('pi_1');
        Mail::fake();

        $dispute = ['id' => 'dp_1', 'charge' => 'ch_1', 'payment_intent' => 'pi_1', 'amount' => $order->total, 'currency' => 'usd',
            'reason' => 'product_not_received', 'status' => 'needs_response', 'evidence_details' => ['due_by' => now()->addDays(7)->timestamp]];
        $this->webhook(['type' => 'charge.dispute.created', 'data' => ['object' => $dispute]], 'whsec_p')->assertOk();
        $this->webhook(['type' => 'charge.dispute.created', 'data' => ['object' => $dispute]], 'whsec_p')->assertOk();   // retried: no second reversal

        $order->refresh();
        $transferred = $order->total - $order->application_fee;
        $this->assertSame(['dp_1', 'needs_response', 'trr_1', $transferred], [$order->dispute['id'], $order->dispute['status'], $order->dispute['reversal_id'], $order->dispute['reversed_amount']]);
        $this->assertCount(1, Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), '/v1/transfers/tr_1/reversals')));
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v1/transfers/tr_1/reversals') && (int) $r['amount'] === $transferred && $r->hasHeader('Idempotency-Key', 'dispute-reversal-dp_1'));
        Mail::assertQueued(\App\Billing\BillingMailer::class, fn ($m) => $m->type === 'dispute_opened' && str_contains($m->render(), '没有收到商品'));
        $this->as()->getJson("/api/admin/orders/{$order->id}")->assertJsonPath('data.dispute.reason_label', '买家称没有收到商品');

        $this->webhook(['type' => 'charge.dispute.closed', 'data' => ['object' => [...$dispute, 'status' => 'won']]], 'whsec_p')->assertOk();
        $order->refresh();
        $this->assertSame(['won', 'tr_back'], [$order->dispute['status'], $order->dispute['returned_transfer_id']]);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v1/transfers') && $r['destination'] === 'acct_1'
            && (int) $r['amount'] === $transferred && $r['source_transaction'] === 'ch_1');
        Mail::assertQueued(\App\Billing\BillingMailer::class, fn ($m) => $m->type === 'dispute_closed');
        $this->assertSame(2, $order->events()->where('type', 'dispute')->count());
    }

    public function test_lost_dispute_on_platform_account_order_only_records_it(): void
    {
        config(['services.stripe.connect' => false]);
        $this->checkout()->assertRedirect();
        $order = Order::withoutGlobalScope('store')->latest('id')->firstOrFail();
        $order->markPaid('pi_9');
        $dispute = ['id' => 'dp_9', 'charge' => 'ch_9', 'payment_intent' => 'pi_9', 'amount' => 500, 'currency' => 'usd', 'reason' => 'fraudulent', 'status' => 'needs_response'];

        $this->webhook(['type' => 'charge.dispute.created', 'data' => ['object' => $dispute]], 'whsec_p')->assertOk();
        $this->webhook(['type' => 'charge.dispute.closed', 'data' => ['object' => [...$dispute, 'status' => 'lost']]], 'whsec_p')->assertOk();
        $order->refresh();
        $this->assertSame('lost', $order->dispute['status']);
        $this->assertArrayNotHasKey('reversal_id', $order->dispute);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/v1/transfers'));

        // Unknown payment intent → ignored
        $this->webhook(['type' => 'charge.dispute.created', 'data' => ['object' => [...$dispute, 'id' => 'dp_x', 'payment_intent' => 'pi_unknown']]], 'whsec_p')->assertOk();
    }
}
