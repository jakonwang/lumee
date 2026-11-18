<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WebhookSubscription;
use App\Webhooks\Webhooks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OAuthAppsTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECT = 'https://app.example.com/oauth/callback';

    private array $dev;       // developer's account + store

    private array $merchant;  // a merchant installing the app

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Webhooks::$resolver = fn (string $h) => in_array($h, ['app.example.com', 'hooks.example.com'], true) ? ['93.184.216.34'] : ['10.0.0.1'];
        Http::fake(['https://app.example.com/*' => Http::response('ok'), 'https://hooks.example.com/*' => Http::response('ok')]);

        $this->dev = $this->register('dev@studio.test', 'Studio Store');
        $this->merchant = $this->register('owner@shop-b.test', 'Shop B');
        $this->as($this->merchant)->postJson('/api/admin/products', ['title' => 'Ring', 'price' => 10, 'status' => 'active'])->assertCreated();
    }

    protected function tearDown(): void
    {
        Webhooks::$resolver = null;
        parent::tearDown();
    }

    private function register(string $email, string $store): array
    {
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'U', 'email' => $email, 'password' => 'secret-pass', 'store_name' => $store])->assertCreated();

        return ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
    }

    private function as(array $who): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$who['token'], 'X-Store-Id' => (string) $who['store_id'], 'Accept' => 'application/json']);
    }

    private function bearer(string $token): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']);
    }

    private function createApp(array $extra = []): array
    {
        $res = $this->as($this->dev)->postJson('/api/admin/partner/apps', [
            'name' => 'Stock Sync', 'tagline' => 'Sync stock with your warehouse', 'description' => 'Keeps inventory in sync.',
            'icon_url' => 'https://app.example.com/icon.png', 'app_url' => 'https://app.example.com/', 'redirect_uris' => [self::REDIRECT],
            'scopes' => ['read_products', 'write_inventory'], 'uninstall_url' => 'https://app.example.com/uninstalled', ...$extra,
        ])->assertCreated();

        return [...$res->json('data'), 'client_secret' => $res->json('client_secret')];
    }

    private function approveAndExchange(array $app, array $who, array $extra = []): array
    {
        $verifier = str_repeat('v', 50);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $url = $this->as($who)->postJson('/api/admin/oauth/authorize', [
            'client_id' => $app['client_id'], 'redirect_uri' => self::REDIRECT, 'scope' => 'read_products write_inventory', 'state' => 'xyz',
            'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
        ])->assertOk()->json('redirect_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertStringStartsWith(self::REDIRECT.'?', $url);
        $this->assertSame(['xyz', (string) $who['store_id']], [$q['state'], $q['store_id']]);

        $this->flushHeaders();
        $res = $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code', 'code' => $q['code'], 'client_id' => $app['client_id'],
            'client_secret' => $app['client_secret'], 'redirect_uri' => self::REDIRECT, 'code_verifier' => $verifier, ...$extra,
        ]);

        return ['response' => $res, 'code' => $q['code'], 'verifier' => $verifier];
    }

    public function test_developer_registers_app_and_it_needs_review_before_others_can_install(): void
    {
        $this->as($this->dev)->postJson('/api/admin/partner/apps', ['name' => 'X', 'app_url' => 'http://evil.example.com', 'redirect_uris' => [self::REDIRECT], 'scopes' => ['read_products']])
            ->assertStatus(422)->assertJsonValidationErrors('app_url');
        $this->as($this->dev)->postJson('/api/admin/partner/apps', ['name' => 'X', 'app_url' => 'https://a.example.com', 'redirect_uris' => [self::REDIRECT], 'scopes' => ['root']])->assertStatus(422);
        $this->as($this->dev)->postJson('/api/admin/partner/apps', ['name' => 'X', 'app_url' => 'https://a.example.com', 'redirect_uris' => [self::REDIRECT], 'scopes' => ['read_products'], 'uninstall_url' => 'https://internal.example.com/x'])
            ->assertStatus(422)->assertJsonValidationErrors('uninstall_url');

        $app = $this->createApp();
        $this->assertStringStartsWith('lms_', $app['client_secret']);
        $this->assertSame(['draft', 32], [$app['status'], strlen($app['client_id'])]);
        $this->as($this->dev)->getJson('/api/admin/partner/apps')->assertOk()->assertJsonPath('data.0.client_id', $app['client_id'])
            ->assertJsonPath('endpoints.token', rtrim(config('app.url'), '/').'/api/oauth/token');
        $this->as($this->merchant)->getJson('/api/admin/partner/apps')->assertJsonCount(0, 'data');   // only your own apps

        // Draft: the developer can test-install on their own store, nobody else can
        $q = ['client_id' => $app['client_id'], 'redirect_uri' => self::REDIRECT];
        $this->as($this->dev)->getJson('/api/admin/oauth/authorize?'.http_build_query($q))->assertOk()->assertJsonPath('listed', false);
        $this->as($this->merchant)->getJson('/api/admin/oauth/authorize?'.http_build_query($q))->assertStatus(422)->assertJsonValidationErrors('oauth');

        // Review by the platform
        $this->as($this->dev)->postJson("/api/admin/partner/apps/{$app['id']}/submit")->assertOk()->assertJsonPath('data.status', 'review');
        $admin = User::where('email', 'owner@shop-b.test')->first();
        $admin->forceFill(['is_platform_admin' => true])->save();
        $this->as($this->merchant)->getJson('/api/ops/apps?status=review')->assertOk()->assertJsonPath('data.0.name', 'Stock Sync');
        $this->as($this->merchant)->postJson("/api/ops/apps/{$app['id']}/review", ['decision' => 'rejected'])->assertStatus(422);
        $this->as($this->merchant)->postJson("/api/ops/apps/{$app['id']}/review", ['decision' => 'listed'])->assertOk();

        $this->as($this->merchant)->getJson('/api/admin/apps')->assertOk()->assertJsonPath('store.0.name', 'Stock Sync')->assertJsonPath('store.0.installed', false);
        $this->as($this->merchant)->getJson('/api/admin/oauth/authorize?'.http_build_query($q))->assertOk()
            ->assertJsonPath('app.name', 'Stock Sync')->assertJsonPath('scopes.0.key', 'read_products')->assertJsonPath('store.name', 'Shop B');
        $this->as($this->merchant)->getJson('/api/admin/oauth/authorize?'.http_build_query([...$q, 'redirect_uri' => 'https://evil.example.com/cb']))->assertStatus(422);
        $this->as($this->merchant)->getJson('/api/admin/oauth/authorize?'.http_build_query([...$q, 'scope' => 'read_customers']))->assertStatus(422);

        // Asking for more scopes sends a listed app back to review
        $this->as($this->dev)->putJson("/api/admin/partner/apps/{$app['id']}", [...$app, 'scopes' => ['read_products', 'write_inventory', 'read_orders']])
            ->assertOk()->assertJsonPath('data.status', 'review');
    }

    public function test_authorization_code_flow_api_access_webhooks_and_uninstall(): void
    {
        $app = $this->createApp();
        \App\Models\App::whereKey($app['id'])->update(['status' => 'listed']);

        // Bad client secret / bad PKCE verifier
        $this->approveAndExchange($app, $this->merchant, ['client_secret' => 'lms_wrong'])['response']->assertStatus(401)->assertJsonPath('error', 'invalid_client');
        $this->approveAndExchange($app, $this->merchant, ['code_verifier' => 'nope'])['response']->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

        $ok = $this->approveAndExchange($app, $this->merchant);
        $ok['response']->assertOk()->assertJsonPath('token_type', 'bearer')->assertJsonPath('scope', 'read_products write_inventory')
            ->assertJsonPath('store.id', $this->merchant['store_id'])->assertHeader('Cache-Control', 'no-store, private');
        $token = $ok['response']->json('access_token');

        // The token works within its scopes, on the installing store only
        $this->bearer($token)->getJson('/api/v1/products')->assertOk()->assertJsonPath('data.0.title', 'Ring');
        $this->bearer($token)->getJson('/api/v1/orders')->assertStatus(403);

        // Apps manage their own webhooks (topics need the matching scope)
        $this->bearer($token)->postJson('/api/v1/webhooks', ['url' => 'https://hooks.example.com/orders', 'topics' => ['orders/create']])->assertStatus(422);
        $hook = $this->bearer($token)->postJson('/api/v1/webhooks', ['url' => 'https://hooks.example.com/products', 'topics' => ['products/update']])
            ->assertCreated()->json('data');
        $this->assertSame(48, strlen($hook['secret']));
        $this->bearer($token)->getJson('/api/v1/webhooks')->assertJsonCount(1, 'data');

        // The merchant sees it as an installed app; its token isn't listed with private tokens
        $installed = $this->as($this->merchant)->getJson('/api/admin/apps')->assertOk()->assertJsonPath('installed.0.app.name', 'Stock Sync');
        $this->assertStringContainsString('store_id='.$this->merchant['store_id'], $installed->json('installed.0.open_url'));
        $this->as($this->merchant)->getJson('/api/admin/developers')->assertJsonCount(0, 'tokens')->assertJsonCount(0, 'webhooks');

        // A code can only be used once — reusing it revokes the token it produced
        $this->flushHeaders();
        $this->postJson('/api/oauth/token', ['grant_type' => 'authorization_code', 'code' => $ok['code'], 'client_id' => $app['client_id'],
            'client_secret' => $app['client_secret'], 'redirect_uri' => self::REDIRECT, 'code_verifier' => $ok['verifier']])->assertStatus(400);
        $this->bearer($token)->getJson('/api/v1')->assertStatus(401);

        // Reinstall, then uninstall: token revoked, webhooks gone, app told (signed)
        $token = $this->approveAndExchange($app, $this->merchant)['response']->assertOk()->json('access_token');
        $this->bearer($token)->postJson('/api/v1/webhooks', ['url' => 'https://hooks.example.com/products', 'topics' => ['products/update']])->assertCreated();
        $installId = $this->as($this->merchant)->getJson('/api/admin/apps')->json('installed.0.id');
        $this->as($this->merchant)->deleteJson("/api/admin/apps/installations/{$installId}")->assertOk();

        $this->bearer($token)->getJson('/api/v1')->assertStatus(401);
        $this->assertSame(0, WebhookSubscription::withoutGlobalScope('store')->whereNotNull('app_installation_id')->count());
        $signing = \App\Models\App::find($app['id'])->signing_secret;
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://app.example.com/uninstalled'
            && $r->header('X-Lumee-Topic')[0] === 'app/uninstalled' && $r['store_id'] === $this->merchant['store_id']
            && hash_equals(base64_encode(hash_hmac('sha256', $r->body(), $signing, true)), $r->header('X-Lumee-Hmac-Sha256')[0]));
        $this->as($this->merchant)->getJson('/api/admin/apps')->assertJsonCount(0, 'installed');

        // Rotating the secret invalidates the old one
        $new = $this->as($this->dev)->postJson("/api/admin/partner/apps/{$app['id']}/secret")->assertOk()->json('client_secret');
        $this->approveAndExchange($app, $this->merchant)['response']->assertStatus(401);
        $this->approveAndExchange([...$app, 'client_secret' => $new], $this->merchant)['response']->assertOk();
    }
}
