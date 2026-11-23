<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** "Continue with Google" for shoppers: start on the shop, Google returns to the platform, the shop signs them in. */
class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.storefront_scheme' => 'http', 'lumee.storefront_port' => null, 'app.url' => 'http://lumee.localhost',
            'services.google_login.client_id' => 'gid', 'services.google_login.client_secret' => 'gsecret']);
        $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => 'a@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop A'])->assertCreated();
        $this->app['auth']->forgetGuards();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at']),
            'openidconnect.googleapis.com/*' => Http::response(['sub' => 'g-123', 'email' => 'Ana@Gmail.com', 'email_verified' => true, 'given_name' => 'Ana', 'family_name' => 'Silva']),
        ]);
    }

    /** Start on the shop and return [state, nonce]. */
    private function start(string $query = ''): array
    {
        $res = $this->get(self::HOST.'/account/google'.$query)->assertRedirect();
        $url = $res->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('http://lumee.localhost/auth/google/callback', $q['redirect_uri']);
        $state = json_decode(Crypt::decryptString($q['state']), true);

        return [$q['state'], $state['nonce'], $state];
    }

    public function test_sign_in_creates_a_verified_account_and_logs_in(): void
    {
        $this->get(self::HOST.'/account/login')->assertSee('Continue with Google');
        [$state, $nonce, $s] = $this->start('?return=/cart');
        $this->assertSame(self::HOST.'/account/google/complete', $s['complete']);

        // Google → platform → back to the shop with a one-time ticket
        $back = $this->get('http://lumee.localhost/auth/google/callback?'.http_build_query(['state' => $state, 'code' => 'c']))->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith(self::HOST.'/account/google/complete?ticket=', $back);

        $this->withSession(['google_login_nonce' => $nonce])->get($back)->assertRedirect('/cart');
        $c = Customer::withoutGlobalScope('store')->sole();
        $this->assertSame(['ana@gmail.com', 'g-123', 'Ana'], [$c->email, $c->google_id, $c->first_name]);
        $this->assertNotNull($c->email_verified_at);
        $this->assertTrue($c->hasAccount());

        // The ticket works once
        $this->withSession(['google_login_nonce' => $nonce])->get($back)->assertRedirect('/account/login');
    }

    public function test_ticket_needs_the_browser_that_started_and_the_same_shop(): void
    {
        [$state, $nonce] = $this->start();
        $back = $this->get('http://lumee.localhost/auth/google/callback?'.http_build_query(['state' => $state, 'code' => 'c']))->headers->get('Location');

        // Someone else's browser (no / wrong nonce) can't use it
        $this->withSession(['google_login_nonce' => 'other'])->get($back)->assertRedirect('/account/login');
        $this->assertSame(0, Customer::withoutGlobalScope('store')->count());

        // Another shop can't use a ticket made for this one
        $this->postJson('/api/admin/auth/register', ['name' => 'B', 'email' => 'b@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop B'])->assertCreated();
        [$state, $nonce] = $this->start();
        $back = $this->get('http://lumee.localhost/auth/google/callback?'.http_build_query(['state' => $state, 'code' => 'c']))->headers->get('Location');
        $this->withSession(['google_login_nonce' => $nonce])->get(str_replace('shop-a', 'shop-b', $back))->assertRedirect('/account/login');

        // A forged state is refused; cancelling on Google's page just goes back to the login page
        $this->get('http://lumee.localhost/auth/google/callback?state=forged&code=c')->assertStatus(400);
        $cancel = $this->get('http://lumee.localhost/auth/google/callback?'.http_build_query(['state' => $state, 'error' => 'access_denied']))->headers->get('Location');
        $this->get($cancel)->assertRedirect('/account/login');
    }

    public function test_existing_customer_is_linked_and_button_hidden_without_keys(): void
    {
        $storeId = Store::withoutGlobalScopes()->value('id');
        Customer::withoutGlobalScope('store')->forceCreate(['store_id' => $storeId, 'email' => 'ana@gmail.com', 'first_name' => 'A']);
        [$state, $nonce] = $this->start();
        $back = $this->get('http://lumee.localhost/auth/google/callback?'.http_build_query(['state' => $state, 'code' => 'c']))->headers->get('Location');
        $this->withSession(['google_login_nonce' => $nonce])->get($back)->assertRedirect('/account');
        $this->assertSame(1, Customer::withoutGlobalScope('store')->count());
        $this->assertSame('g-123', Customer::withoutGlobalScope('store')->value('google_id'));

        config(['services.google_login.client_id' => null]);
        $this->get(self::HOST.'/account/login')->assertDontSee('Continue with Google');
        $this->get(self::HOST.'/account/google')->assertNotFound();
    }
}
