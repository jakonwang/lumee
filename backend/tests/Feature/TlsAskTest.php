<?php

namespace Tests\Feature;

use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TlsAskTest extends TestCase
{
    use RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();
        config(['plans.test_mode' => true, 'services.stripe.secret' => null, 'lumee.tls_ask_token' => 'tok']);
        Mail::fake();
        $res = $this->postJson('/api/admin/auth/register', [
            'name' => 'M', 'email' => 'owner@shop-a.test', 'password' => 'secret-pass', 'store_name' => 'Shop A',
        ])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
    }

    private function ask(string $domain, string $ip = '127.0.0.1', string $token = ''): \Illuminate\Testing\TestResponse
    {
        $this->flushHeaders();

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->get('http://127.0.0.1/internal/tls/ask?domain='.urlencode($domain).($token ? '&token='.$token : ''));
    }

    public function test_only_hosts_we_serve_get_certificates(): void
    {
        $store = Store::find($this->a['store_id']);
        $store->domains()->create(['host' => 'shop.example.com', 'type' => 'custom', 'is_primary' => false, 'verified_at' => now()]);
        $store->domains()->create(['host' => 'pending.example.com', 'type' => 'custom', 'is_primary' => false]);

        $this->ask('shop.example.com')->assertOk();
        $this->ask('SHOP.example.com.')->assertOk();
        $this->ask('pending.example.com')->assertNotFound();      // not verified yet
        $this->ask('random.example.org')->assertNotFound();
        $this->ask('')->assertNotFound();

        $this->ask($store->handle.'.lumee.localhost')->assertOk();   // free subdomain
        $this->ask('nobody.lumee.localhost')->assertNotFound();
        $this->ask('a.'.$store->handle.'.lumee.localhost')->assertNotFound();
        $this->ask('lumee.localhost')->assertOk();

        // Only Caddy (loopback) or a caller holding the token may ask
        $this->ask('shop.example.com', '203.0.113.9')->assertForbidden();
        $this->ask('shop.example.com', '203.0.113.9', 'wrong')->assertForbidden();
        $this->ask('shop.example.com', '203.0.113.9', 'tok')->assertOk();
    }

    public function test_custom_domain_refused_on_a_plan_without_custom_domains(): void
    {
        Store::find($this->a['store_id'])->domains()->create(['host' => 'shop.example.com', 'type' => 'custom', 'is_primary' => false, 'verified_at' => now()]);
        $this->ask('shop.example.com')->assertOk();   // trial includes custom domains

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$this->a['token'], 'X-Store-Id' => (string) $this->a['store_id'], 'Accept' => 'application/json'])
            ->postJson('/api/admin/billing/subscribe', ['plan' => 'starter', 'interval' => 'month'])->assertOk();
        $this->ask('shop.example.com')->assertNotFound();
    }
}
