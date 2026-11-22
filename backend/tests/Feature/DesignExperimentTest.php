<?php

namespace Tests\Feature;

use App\LeadFinder\LeadAi;
use App\Models\DesignExperiment;
use App\Models\DesignInsight;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\ShippingRate;
use App\Models\ThemeVersion;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Phase E: design A/B tests per site, performance numbers per market, weekly findings and suggestions. */
class DesignExperimentTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private array $a;

    private int $mx;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.payments.test_gateway' => true, 'lumee.storefront_scheme' => 'http', 'lumee.storefront_port' => null]);
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => 'a@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop A'])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
        Http::fake(['open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['USD' => 1, 'MXN' => 20]])]);
        $this->artisan('lumee:exchange-rates')->assertSuccessful();
        $this->mx = $this->as()->postJson('/api/admin/markets', ['name' => 'Mexico', 'handle' => 'mx', 'countries' => ['MX'], 'currency' => 'MXN',
            'default_locale' => 'es', 'locales' => [], 'price_adjustment' => 0, 'rounding' => 'none', 'enabled' => true])->assertCreated()->json('data.id');
    }

    private function as(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->a['token'], 'X-Store-Id' => (string) $this->a['store_id'], 'Accept' => 'application/json']);
    }

    private function visitor(?string $id = null, string $ua = 'Mozilla/5.0 (Windows NT 10.0) Chrome/130'): static
    {
        $this->flushHeaders();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->app['auth']->forgetGuards();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->withHeader('User-Agent', $ua);

        return $id ? $this->withCookie('lm_v', $id) : $this;
    }

    /** Main-site draft with a different hero heading, then an A/B test of it. */
    private function startMainTest(): array
    {
        $d = $this->as()->getJson('/api/admin/theme')->json();
        $this->as()->postJson('/api/admin/theme/publish', ['id' => $d['id']])->assertOk();
        $c = $d['config'];
        $i = collect($c['sections'])->search(fn ($s) => $s['type'] === 'hero');
        $c['sections'][$i]['settings']['heading'] = 'Version B heading';
        $this->as()->putJson('/api/admin/theme', [...$c, 'id' => $d['id']])->assertOk();

        return [$d, $this->as()->postJson('/api/admin/theme/experiments', ['id' => $d['id'], 'name' => 'Hero test'])->assertCreated()->json('data')];
    }

    public function test_visitors_are_split_and_always_see_the_same_version(): void
    {
        $d = $this->as()->getJson('/api/admin/theme')->json();
        $this->as()->postJson('/api/admin/theme/experiments', ['id' => $d['id'], 'name' => 'Nothing to test'])->assertJsonValidationErrors('experiment');   // draft = live
        [$d, $e] = $this->startMainTest();
        $this->as()->postJson('/api/admin/theme/experiments', ['id' => $d['id'], 'name' => 'Again'])->assertJsonValidationErrors('experiment');
        $this->assertSame('Hero test', $this->as()->getJson('/api/admin/theme')->json('experiment.name'));

        $seen = ['a' => 0, 'b' => 0];
        for ($n = 0; $n < 24; $n++) {
            $id = (string) Str::uuid();
            $html = $this->visitor($id)->get(self::HOST.'/')->assertOk()->getContent();
            $b = str_contains($html, 'Version B heading');
            $seen[$b ? 'b' : 'a']++;
            $this->assertSame($b, str_contains($this->visitor($id)->get(self::HOST.'/')->getContent(), 'Version B heading'));   // same version again
            $arm = DB::table('analytics_sessions')->where('visitor', $id)->value('design_arm');
            $this->assertSame($b ? 'b' : 'a', $arm);
        }
        $this->assertGreaterThan(0, $seen['a']);
        $this->assertGreaterThan(0, $seen['b']);

        // A first visit (no cookie yet) is credited to the version it was shown
        $html = $this->visitor()->get(self::HOST.'/')->getContent();
        $last = DB::table('analytics_sessions')->latest('id')->first();
        $this->assertSame(str_contains($html, 'Version B heading') ? 'b' : 'a', $last->design_arm);

        // Bots and other sites aren't part of it
        $this->visitor((string) Str::uuid(), 'Googlebot/2.1')->get(self::HOST.'/')->assertDontSee('Version B heading');
        $this->visitor((string) Str::uuid())->get(self::HOST.'/es-mx')->assertDontSee('Version B heading');
        $this->assertNull(DB::table('analytics_sessions')->latest('id')->value('design_experiment_id'));   // the Mexico visit
        $this->assertSame($this->mx, (int) DB::table('analytics_sessions')->latest('id')->value('market_id'));
    }

    public function test_results_and_adopting_version_b(): void
    {
        [$d, $e] = $this->startMainTest();
        // A real order to attach to sessions (orders: 2% in A, 8% in B)
        $variant = ProductVariant::withoutGlobalScope('store')->whereHas('product', fn ($q) => $q)->value('id')
            ?? ProductVariant::withoutGlobalScope('store')->where('product_id', $this->as()->postJson('/api/admin/products', ['title' => 'Ring', 'price' => 40, 'inventory' => 50, 'status' => 'active'])->json('data.id'))->value('id');
        $cart = $this->visitor()->post(self::HOST.'/cart/add', ['variant_id' => $variant])->getCookie('lumee_cart', false)->getValue();
        $rate = ShippingRate::withoutGlobalScope('store')->where('price', '>', 0)->first();
        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/checkout', ['email' => 'b@example.com', 'first_name' => 'B', 'last_name' => 'C',
            'address1' => '1 St', 'city' => 'Austin', 'zip' => '78701', 'country' => 'US', 'province' => 'TX', 'shipping_rate_id' => $rate->id, 'payment_gateway' => 'test'])->assertRedirect();
        $order = Order::withoutGlobalScope('store')->firstOrFail();
        DB::table('analytics_sessions')->delete();
        foreach (['a' => 6, 'b' => 24] as $arm => $orders) {
            $rows = [];
            for ($n = 0; $n < 300; $n++) {
                $rows[] = ['store_id' => $this->a['store_id'], 'token' => (string) Str::uuid(), 'visitor' => (string) Str::uuid(), 'started_at' => now(), 'last_seen_at' => now(),
                    'pageviews' => 2, 'added_to_cart' => $n < $orders * 2, 'order_id' => $n < $orders ? $order->id : null, 'design_experiment_id' => $e['id'], 'design_arm' => $arm];
            }
            DB::table('analytics_sessions')->insert($rows);
        }

        $p = $this->as()->getJson('/api/admin/design/performance')->assertOk()->json();
        $x = collect($p['experiments'])->firstWhere('id', $e['id']);
        $this->assertEquals([300, 2, 300, 8], [$x['stats']['a']['sessions'], $x['stats']['a']['conversion'], $x['stats']['b']['sessions'], $x['stats']['b']['conversion']]);
        $this->assertSame('b', $x['stats']['leader']);
        $this->assertTrue($x['stats']['ready']);
        $this->assertEquals(300, $x['stats']['uplift']);
        $this->assertGreaterThan(0, $x['stats']['b']['revenue_per_session']);

        // Adopt B: it's published for everyone
        $this->as()->postJson("/api/admin/design/experiments/{$e['id']}/finish", ['winner' => 'b'])->assertOk()->assertJsonPath('data.status', 'finished');
        $this->visitor((string) Str::uuid())->get(self::HOST.'/')->assertSee('Version B heading');
        $this->assertSame('experiment', ThemeVersion::withoutGlobalScope('store')->latest('id')->value('source'));
        $this->assertNull($this->as()->getJson('/api/admin/theme')->json('experiment'));
    }

    public function test_market_test_and_a_campaign_stops_it(): void
    {
        $d = $this->as()->getJson("/api/admin/theme?market={$this->mx}")->json();
        $c = $d['config'];
        $c['settings']['header']['announcement_text'] = 'Prueba B';
        $this->as()->putJson('/api/admin/theme', [...$c, 'id' => $d['id'], 'market' => $this->mx])->assertOk();
        $e = $this->as()->postJson('/api/admin/theme/experiments', ['id' => $d['id'], 'market' => $this->mx, 'name' => 'Anuncio'])->assertCreated()->json('data');
        $seen = 0;
        for ($n = 0; $n < 16; $n++) {
            $seen += (int) str_contains($this->visitor((string) Str::uuid())->get(self::HOST.'/es-mx')->getContent(), 'Prueba B');
        }
        $this->assertGreaterThan(0, $seen);
        $this->assertLessThan(16, $seen);
        $this->visitor((string) Str::uuid())->get(self::HOST.'/')->assertDontSee('Prueba B');

        // A campaign going live on the same site stops the test
        $s = $this->as()->postJson('/api/admin/theme/schedules', ['id' => $d['id'], 'market' => $this->mx, 'name' => 'Sale', 'starts_at' => now()->addMinute()->toDateTimeString(), 'ends_at' => now()->addDay()->toDateTimeString()])->assertCreated();
        $this->travel(2)->minutes();
        $this->artisan('lumee:theme-schedules');
        $this->assertSame('stopped', DesignExperiment::withoutGlobalScope('store')->find($e['id'])->status);
        $this->as()->postJson('/api/admin/theme/experiments', ['id' => $d['id'], 'market' => $this->mx, 'name' => 'x'])->assertJsonValidationErrors('experiment');   // not during a campaign
    }

    public function test_performance_by_market_and_weekly_insights(): void
    {
        $rows = [];
        foreach (range(1, 80) as $n) {   // Mexico: phones bounce a lot
            $mobile = $n <= 60;
            $rows[] = ['store_id' => $this->a['store_id'], 'market_id' => $this->mx, 'token' => (string) Str::uuid(), 'visitor' => (string) Str::uuid(),
                'started_at' => now()->subDays(2), 'last_seen_at' => now()->subDays(2), 'device' => $mobile ? 'mobile' : 'desktop',
                'pageviews' => $mobile && $n <= 45 ? 1 : 3, 'viewed_product' => ! ($mobile && $n <= 45), 'added_to_cart' => $n % 10 === 0, 'landing_path' => '/es-mx'];
        }
        foreach (range(1, 20) as $n) {   // main site: a few visits
            $rows[] = ['store_id' => $this->a['store_id'], 'market_id' => null, 'token' => (string) Str::uuid(), 'visitor' => (string) Str::uuid(),
                'started_at' => now()->subDays(3), 'last_seen_at' => now()->subDays(3), 'device' => 'desktop', 'pageviews' => 2, 'viewed_product' => true, 'added_to_cart' => false, 'landing_path' => '/'];
        }
        DB::table('analytics_sessions')->insert($rows);

        $p = $this->as()->getJson('/api/admin/design/performance?days=7')->assertOk()->json();
        $mx = collect($p['sites'])->firstWhere('market.id', $this->mx);
        $this->assertSame(80, $mx['now']['sessions']);
        $this->assertEquals(75, $mx['now']['mobile_share']);
        $this->assertEquals(75, $mx['now']['mobile']['bounce']);
        $this->assertEquals(56.3, $mx['now']['home_bounce']);
        $this->assertSame(20, collect($p['sites'])->firstWhere('market', null)['now']['sessions']);

        // Weekly look: findings from the numbers; Claude's suggestions when there's a key
        config(['services.anthropic.key' => 'test-key']);
        $this->mock(LeadAi::class, fn ($m) => $m->shouldReceive('json')->twice()->andReturn(['suggestions' => [['title' => '把限时抢购移到第二屏', 'detail' => '手机跳出率高。']]]));
        $this->as()->postJson('/api/admin/design/insights/refresh')->assertOk()->assertJson(['sites' => 1]);   // the main site has too few visits
        $i = DesignInsight::withoutGlobalScope('store')->sole();
        $this->assertSame($this->mx, $i->market_id);
        $this->assertContains('mobile_bounce', array_column($i->findings, 'code'));
        $this->assertContains('home_bounce', array_column($i->findings, 'code'));
        $this->assertSame('把限时抢购移到第二屏', $i->suggestions[0]['title']);
        $this->assertSame('Mexico', $this->as()->getJson('/api/admin/design/performance')->json('insights.0.market'));

        // The weekly job updates the same week rather than adding another
        $this->artisan('lumee:design-insights')->assertSuccessful();
        $this->assertSame(1, DesignInsight::withoutGlobalScope('store')->count());
    }
}
