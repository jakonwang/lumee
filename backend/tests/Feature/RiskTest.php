<?php

namespace Tests\Feature;

use App\Models\BlocklistEntry;
use App\Models\Order;
use App\Models\ShippingRate;
use App\Models\Store;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RiskTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private array $a;

    private int $variant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.payments.test_gateway' => true]);
        Mail::fake();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => 'a@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop A'])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
        $this->variant = $this->as()->postJson('/api/admin/products', ['title' => 'Ring', 'price' => 20, 'inventory' => 500, 'status' => 'active'])->json('data.variants.0.id');
    }

    private function as(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->a['token'], 'X-Store-Id' => (string) $this->a['store_id'], 'Accept' => 'application/json']);
    }

    /** Place a storefront order (optionally from a given IP / CDN country). */
    private function buy(string $email, int $qty = 1, array $server = [], string $country = 'US')
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->defaultCookies = $this->unencryptedCookies = [];
        $cart = $this->post(self::HOST.'/cart/add', ['variant_id' => $this->variant, 'quantity' => $qty])->getCookie('lumee_cart', false)->getValue();

        return $this->withServerVariables($server)->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/checkout', [
            'email' => $email, 'first_name' => 'A', 'last_name' => 'B', 'address1' => '1 St', 'city' => 'X', 'zip' => '10001', 'country' => $country,
            'shipping_rate_id' => ShippingRate::withoutGlobalScope('store')->where('store_id', $this->a['store_id'])->value('id'), 'payment_gateway' => 'test',
        ]);
    }

    private function last(): Order
    {
        return Order::withoutGlobalScope('store')->latest('id')->firstOrFail();
    }

    public function test_normal_order_is_low_risk(): void
    {
        $this->buy('jo@example.com', 1, ['REMOTE_ADDR' => '198.51.100.1', 'HTTP_CF_IPCOUNTRY' => 'US'])->assertRedirect();
        $o = $this->last();
        $this->assertSame('low', $o->risk_level);
        $this->assertSame(0, $o->risk_score);
        $this->assertSame('198.51.100.1', $o->client_ip);
        $this->assertSame('US', $o->client_country);
        $this->assertFalse($o->risk_hold);
    }

    public function test_risky_signals_add_up_and_high_risk_is_held(): void
    {
        $ip = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_CF_IPCOUNTRY' => 'NG'];
        $this->buy('a1@mailinator.com', 1, $ip)->assertRedirect();
        $first = $this->last();
        $this->assertSame('medium', $first->risk_level);   // country mismatch 25 + disposable 30 = 55
        $this->assertEqualsCanonicalizing(['ip_country_mismatch', 'disposable_email'], array_column($first->risk_reasons, 'code'));

        $this->buy('a2@mailinator.com', 1, $ip);
        $this->buy('a3@mailinator.com', 12, $ip);
        $o = $this->last();
        $codes = array_column($o->risk_reasons, 'code');
        $this->assertContains('ip_velocity', $codes);
        $this->assertContains('many_emails_same_ip', $codes);
        $this->assertContains('bulk_quantity', $codes);
        $this->assertSame('high', $o->risk_level);
        $this->assertSame(100, $o->risk_score);
        $this->assertTrue($o->risk_hold);

        // Held: can't ship until reviewed; the admin sees readable reasons
        $this->as()->getJson("/api/admin/orders/{$o->id}")->assertOk()->assertJsonPath('data.risk_level', 'high')->assertJsonPath('data.risk_hold', true)
            ->assertJsonPath('data.risk_reasons.0.label', '下单 IP 在 NG，收货地址在 US');
        $this->as()->postJson("/api/admin/orders/{$o->id}/fulfill", ['tracking_number' => 'X1'])->assertStatus(422)->assertJsonValidationErrors('order');
        $this->as()->postJson("/api/admin/orders/{$o->id}/risk/release")->assertOk();
        $this->as()->postJson("/api/admin/orders/{$o->id}/fulfill", ['tracking_number' => 'X1'])->assertOk();
        $this->as()->getJson('/api/admin/settings/risk')->assertJsonPath('high_30d', 1)->assertJsonPath('held', 0);
    }

    public function test_hold_and_scoring_switches(): void
    {
        $this->as()->putJson('/api/admin/settings/risk', ['enabled' => true, 'hold_high' => false])->assertOk()->assertJsonPath('settings.hold_high', false);
        $ip = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_CF_IPCOUNTRY' => 'NG'];
        foreach (['b1', 'b2', 'b3'] as $u) {
            $this->buy("{$u}@mailinator.com", 12, $ip);
        }
        $this->assertSame('high', $this->last()->risk_level);
        $this->assertFalse($this->last()->risk_hold);

        $this->as()->putJson('/api/admin/settings/risk', ['enabled' => false, 'hold_high' => true])->assertOk();
        $this->buy('c@mailinator.com', 1, $ip);
        $this->assertNull($this->last()->risk_level);
    }

    public function test_blocklist_stops_checkout(): void
    {
        $this->as()->postJson('/api/admin/settings/risk/blocklist', ['type' => 'email', 'value' => ' Bad@Example.com '])->assertCreated()->assertJsonPath('blocklist.0.value', 'bad@example.com');
        $this->as()->postJson('/api/admin/settings/risk/blocklist', ['type' => 'domain', 'value' => '@spam.test'])->assertCreated();
        $this->as()->postJson('/api/admin/settings/risk/blocklist', ['type' => 'ip', 'value' => '192.0.2.5'])->assertCreated();
        $this->as()->postJson('/api/admin/settings/risk/blocklist', ['type' => 'ip', 'value' => 'nope'])->assertStatus(422);

        $this->buy('bad@example.com')->assertSessionHasErrors('email');
        $this->buy('x@spam.test')->assertSessionHasErrors('email');
        $this->buy('ok@example.com', 1, ['REMOTE_ADDR' => '192.0.2.5'])->assertSessionHasErrors('email');
        $this->assertSame(0, Order::withoutGlobalScope('store')->count());
        $this->buy('ok@example.com', 1, ['REMOTE_ADDR' => '192.0.2.6'])->assertRedirect()->assertSessionDoesntHaveErrors();
        $this->assertSame(3, (int) BlocklistEntry::withoutGlobalScope('store')->sum('hits'));

        // Store isolation: another store's admin can't remove it
        $id = BlocklistEntry::withoutGlobalScope('store')->value('id');
        $b = $this->postJson('/api/admin/auth/register', ['name' => 'B', 'email' => 'b@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop B'])->json();
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$b['token'], 'X-Store-Id' => (string) $b['stores'][0]['id']])->deleteJson("/api/admin/settings/risk/blocklist/{$id}")->assertNotFound();
        $this->as()->deleteJson("/api/admin/settings/risk/blocklist/{$id}")->assertOk();
    }

    public function test_stripe_radar_raises_the_level(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test']);
        $this->buy('rad@example.com')->assertRedirect();
        $o = $this->last();
        $o->forceFill(['payment_gateway' => 'stripe', 'payment_reference' => 'pi_123'])->save();
        $payload = json_encode(['id' => 'evt_1', 'type' => 'charge.succeeded', 'data' => ['object' => [
            'id' => 'ch_1', 'payment_intent' => 'pi_123', 'metadata' => [], 'outcome' => ['risk_level' => 'highest', 'risk_score' => 91],
        ]]]);
        $ts = time();
        $sig = 't='.$ts.',v1='.hash_hmac('sha256', $ts.'.'.$payload, 'whsec_test');
        $this->flushHeaders();
        $this->call('POST', '/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $payload)->assertOk();
        $o->refresh();
        $this->assertSame('high', $o->risk_level);
        $this->assertTrue($o->risk_hold);
        $this->assertSame('radar', $o->risk_reasons[0]['code']);
        $this->assertTrue($o->events()->where('type', 'risk')->exists());
    }

    public function test_merchant_email_shows_the_warning(): void
    {
        Store::whereKey($this->a['store_id'])->update(['settings' => ['risk' => ['enabled' => true, 'hold_high' => true]]]);
        $this->buy('w@mailinator.com', 1, ['REMOTE_ADDR' => '203.0.113.1', 'HTTP_CF_IPCOUNTRY' => 'NG']);
        Mail::assertSent(\App\Notifications\OrderMail::class, fn ($m) => str_contains($m->render(), '使用临时邮箱'));
    }
}
