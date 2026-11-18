<?php

namespace Tests\Feature;

use App\Apps\SessionToken;
use App\Models\ApiToken;
use App\Models\App;
use App\Models\AppCharge;
use App\Models\AppInstallation;
use App\Models\PlatformInvoice;
use App\Models\Store;
use App\Models\StoreTheme;
use App\Models\ThemeListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Theme marketplace, app billing (charges + revenue share) and App Bridge session tokens. */
class PlatformMarketTest extends TestCase
{
    use RefreshDatabase;

    private array $dev;

    private array $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake(config('lumee.media_disk'));
        Storage::fake('local');
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

    private function bearer(string $token): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']);
    }

    /** Export the developer store's live theme as a package. */
    private function package(): UploadedFile
    {
        $config = $this->as($this->dev)->getJson('/api/admin/theme')->json('config');
        $config['sections'][0]['settings']['heading'] = 'Marketplace hero';
        $this->as($this->dev)->putJson('/api/admin/theme', $config)->assertOk();
        $id = $this->as($this->dev)->getJson('/api/admin/themes')->json('data.0.id');
        $res = $this->as($this->dev)->get("/api/admin/themes/{$id}/export")->assertOk();
        $copy = tempnam(sys_get_temp_dir(), 'mk').'.zip';
        copy($res->baseResponse->getFile()->getPathname(), $copy);

        return new UploadedFile($copy, 'pkg.zip', 'application/zip', null, true);
    }

    public function test_theme_marketplace_submit_review_install(): void
    {
        $listing = $this->as($this->dev)->postJson('/api/admin/partner/themes', ['name' => 'Aurora Pro', 'tagline' => 'Luxury jewelry theme'])
            ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data');
        $this->as($this->dev)->postJson("/api/admin/partner/themes/{$listing['id']}/submit")->assertStatus(422);   // no package yet

        $bad = UploadedFile::fake()->create('x.zip', 10, 'application/zip');
        $this->as($this->dev)->post("/api/admin/partner/themes/{$listing['id']}/package", ['file' => $bad])->assertStatus(422);
        $this->as($this->dev)->post("/api/admin/partner/themes/{$listing['id']}/package", ['file' => $this->package()])->assertOk()
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.has_package', true);
        $this->as($this->dev)->postJson("/api/admin/partner/themes/{$listing['id']}/submit")->assertStatus(422)->assertJsonValidationErrors('preview_url');
        $this->as($this->dev)->putJson("/api/admin/partner/themes/{$listing['id']}", ['name' => 'Aurora Pro', 'preview_url' => 'http://x.test/a.png'])->assertStatus(422);
        $this->as($this->dev)->putJson("/api/admin/partner/themes/{$listing['id']}", ['name' => 'Aurora Pro', 'preview_url' => 'https://cdn.test/a.png'])->assertOk();
        $this->as($this->dev)->postJson("/api/admin/partner/themes/{$listing['id']}/submit")->assertOk()->assertJsonPath('data.status', 'review');

        // Not listed yet: merchants can't see or install it
        $this->as($this->shop)->getJson('/api/admin/themes/market')->assertJsonCount(0, 'data');
        $this->as($this->shop)->postJson("/api/admin/themes/market/{$listing['id']}/install")->assertNotFound();

        // Ops approve (platform admins only)
        $this->as($this->shop)->postJson("/api/ops/themes/{$listing['id']}/review", ['decision' => 'listed'])->assertForbidden();
        User::where('email', 'owner@shop.test')->first()->forceFill(['is_platform_admin' => true])->save();
        $this->as($this->shop)->getJson('/api/ops/themes?status=review')->assertJsonPath('data.0.developer.email', 'dev@studio.test');
        $this->as($this->shop)->get("/api/ops/themes/{$listing['id']}/package")->assertOk();
        $this->as($this->shop)->postJson("/api/ops/themes/{$listing['id']}/review", ['decision' => 'rejected'])->assertStatus(422);   // needs a note
        $this->as($this->shop)->postJson("/api/ops/themes/{$listing['id']}/review", ['decision' => 'listed'])->assertOk();

        // Merchant installs a copy into their library (not live)
        $this->as($this->shop)->getJson('/api/admin/themes/market')->assertJsonPath('data.0.name', 'Aurora Pro')->assertJsonPath('data.0.installed', false);
        $this->as($this->shop)->postJson("/api/admin/themes/market/{$listing['id']}/install")->assertCreated()
            ->assertJsonPath('data.source', 'market')->assertJsonPath('data.live', false);
        $row = StoreTheme::withoutGlobalScope('store')->where('store_id', $this->shop['store_id'])->where('source', 'market')->sole();
        $this->assertSame('Marketplace hero', $row->draft['sections'][0]['settings']['heading']);
        $this->assertSame(1, ThemeListing::find($listing['id'])->installs);
        $this->as($this->shop)->getJson('/api/admin/themes/market')->assertJsonPath('data.0.installed', true);

        // A new package version sends a listed theme back to review; another developer can't touch it
        $this->as($this->shop)->putJson("/api/admin/partner/themes/{$listing['id']}", ['name' => 'Hijack'])->assertNotFound();
        $this->as($this->dev)->post("/api/admin/partner/themes/{$listing['id']}/package", ['file' => $this->package()])->assertJsonPath('data.status', 'review')->assertJsonPath('data.version', 2);
        Storage::disk('local')->assertMissing("theme-listings/{$listing['id']}/v1.zip");
    }

    /** A listed app installed on the shop, with an app API token. */
    private function installedApp(array $extra = []): array
    {
        $app = $this->as($this->dev)->postJson('/api/admin/partner/apps', [
            'name' => 'Upsell Pro', 'app_url' => 'https://upsell.example.com/', 'redirect_uris' => ['https://upsell.example.com/cb'], 'scopes' => ['read_products'], ...$extra,
        ])->assertCreated()->json('data');
        App::whereKey($app['id'])->update(['status' => 'listed']);
        $store = Store::find($this->shop['store_id']);
        $install = AppInstallation::withoutGlobalScope('store')->forceCreate(['store_id' => $store->id, 'app_id' => $app['id'], 'scopes' => ['read_products'], 'installed_at' => now()]);
        [$token, $plain] = ApiToken::issue($store, 'Upsell Pro', ['read_products'], null);
        $token->forceFill(['app_installation_id' => $install->id])->save();

        return ['app' => $app, 'install' => $install, 'token' => $plain];
    }

    public function test_app_charges_approval_billing_and_revenue_share(): void
    {
        ['app' => $app, 'install' => $install, 'token' => $token] = $this->installedApp();
        $store = Store::find($this->shop['store_id']);
        app(\App\Billing\Billing::class)->upsert($store, ['plan' => 'basic', 'interval' => 'month', 'status' => 'active', 'provider' => 'test', 'current_period_end' => now()->addYears(2)]);

        // Store API tokens (not apps) can't create charges; return URL must be the app's
        [, $storeToken] = ApiToken::issue($store, 'Mine', ['read_products'], null);
        $body = ['name' => 'Pro plan', 'price' => 19.99, 'trial_days' => 7, 'return_url' => 'https://upsell.example.com/billing'];
        $this->bearer($storeToken)->postJson('/api/v1/app_charges', $body)->assertForbidden();
        $this->bearer($token)->postJson('/api/v1/app_charges', [...$body, 'return_url' => 'https://evil.test/x'])->assertStatus(422);
        $charge = $this->bearer($token)->postJson('/api/v1/app_charges', $body)->assertCreated()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.price', '19.99')->json('data');
        $tokenPart = basename($charge['confirmation_url']);

        // Merchant approves in the admin → back to the app; 7-day trial first
        $this->as($this->shop)->getJson("/api/admin/apps/charges/{$tokenPart}")->assertOk()->assertJsonPath('data.app.name', 'Upsell Pro')->assertJsonPath('data.trial_available', true);
        $this->as($this->dev)->getJson("/api/admin/apps/charges/{$tokenPart}")->assertNotFound();   // another store
        $this->as($this->shop)->postJson("/api/admin/apps/charges/{$tokenPart}/approve")->assertOk()
            ->assertJsonPath('redirect_url', "https://upsell.example.com/billing?charge_id={$charge['id']}");
        $this->as($this->shop)->postJson("/api/admin/apps/charges/{$tokenPart}/approve")->assertStatus(422);
        $this->bearer($token)->getJson("/api/v1/app_charges/{$charge['id']}")->assertJsonPath('data.status', 'active')->assertJsonPath('data.confirmation_url', null);
        $this->assertSame(0, PlatformInvoice::withoutGlobalScope('store')->where('kind', 'apps')->count());   // in trial
        $this->as($this->shop)->getJson('/api/admin/apps')->assertJsonPath('installed.0.charges.0.name', 'Pro plan')->assertJsonPath('monthly_app_spend', '19.99');

        // After the trial: billed on the platform invoice; developer earns 80%
        $this->travel(8)->days();
        $this->artisan('lumee:bill-app-charges')->expectsOutput('Billed 1 app charge(s).');
        $this->artisan('lumee:bill-app-charges')->expectsOutput('Billed 0 app charge(s).');
        $inv = PlatformInvoice::withoutGlobalScope('store')->where('kind', 'apps')->sole();
        $this->assertSame(1999, $inv->amount);
        $this->assertSame('paid', $inv->status);   // test-mode subscription
        $this->as($this->dev)->getJson('/api/admin/partner/apps')->assertJsonPath('data.0.earnings.total', '15.99')->assertJsonPath('data.0.earnings.active_charges', 1);
        $this->travel(30)->days();
        $this->artisan('lumee:bill-app-charges')->expectsOutput('Billed 1 app charge(s).');

        // Plan change: approving a new recurring charge replaces the old one (trial only once)
        $new = $this->bearer($token)->postJson('/api/v1/app_charges', [...$body, 'name' => 'Plus', 'price' => 49])->json('data');
        $this->as($this->shop)->getJson('/api/admin/apps/charges/'.basename($new['confirmation_url']))->assertJsonPath('data.trial_available', false);
        $this->as($this->shop)->postJson('/api/admin/apps/charges/'.basename($new['confirmation_url']).'/approve')->assertOk();
        $this->assertSame('cancelled', AppCharge::find($charge['id'])->status);
        $this->assertSame(3, PlatformInvoice::withoutGlobalScope('store')->where('kind', 'apps')->count());   // billed now, no second trial

        // Decline; uninstall cancels the running plan
        $x = $this->bearer($token)->postJson('/api/v1/app_charges', [...$body, 'name' => 'Addon', 'interval' => 'one_time', 'price' => 5])->json('data');
        $this->as($this->shop)->postJson('/api/admin/apps/charges/'.basename($x['confirmation_url']).'/decline')->assertOk();
        $this->assertSame('declined', AppCharge::find($x['id'])->status);
        $this->as($this->shop)->deleteJson("/api/admin/apps/installations/{$install->id}")->assertOk();
        $this->assertSame('cancelled', AppCharge::find($new['id'])->status);
    }

    public function test_test_charges_are_never_billed_and_one_time_bills_at_once(): void
    {
        ['token' => $token] = $this->installedApp();
        $t = $this->bearer($token)->postJson('/api/v1/app_charges', ['name' => 'Dev', 'price' => 10, 'return_url' => 'https://upsell.example.com/b', 'test' => true])->json('data');
        $this->as($this->shop)->postJson('/api/admin/apps/charges/'.basename($t['confirmation_url']).'/approve')->assertOk();
        $o = $this->bearer($token)->postJson('/api/v1/app_charges', ['name' => 'Setup', 'price' => 30, 'interval' => 'one_time', 'return_url' => 'https://upsell.example.com/b'])->json('data');
        $this->as($this->shop)->postJson('/api/admin/apps/charges/'.basename($o['confirmation_url']).'/approve')->assertOk();
        $this->assertSame([3000], PlatformInvoice::withoutGlobalScope('store')->where('kind', 'apps')->pluck('amount')->all());
        $this->bearer($token)->deleteJson("/api/v1/app_charges/{$o['id']}")->assertJsonPath('data.status', 'active');   // paid one-time stays
        $this->bearer($token)->deleteJson("/api/v1/app_charges/{$t['id']}")->assertJsonPath('data.status', 'cancelled');
    }

    public function test_app_bridge_session_token(): void
    {
        ['app' => $app, 'install' => $install] = $this->installedApp(['embedded' => true, 'pricing' => 'Free · Pro $19/month']);
        $res = $this->as($this->shop)->postJson("/api/admin/apps/installations/{$install->id}/session")->assertOk()
            ->assertJsonPath('origin', 'https://upsell.example.com')->assertJsonPath('app.embedded', true)->assertJsonPath('app.pricing', 'Free · Pro $19/month');
        $this->assertStringContainsString('embedded=1', $res->json('url'));

        $model = App::find($app['id']);
        $claims = SessionToken::verify($res->json('token'), $model->signing_secret, $model->client_id);
        $this->assertSame($this->shop['store_id'], $claims['store_id']);
        $this->assertNull(SessionToken::verify($res->json('token'), 'wrong-secret', $model->client_id));
        $this->assertNull(SessionToken::verify($res->json('token'), $model->signing_secret, 'other-client'));
        $this->travel(2)->minutes();
        $this->assertNull(SessionToken::verify($res->json('token'), $model->signing_secret, $model->client_id));   // expired

        // Other stores can't open this installation
        $this->as($this->dev)->postJson("/api/admin/apps/installations/{$install->id}/session")->assertNotFound();
    }
}
