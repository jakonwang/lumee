<?php

namespace Tests\Feature;

use App\Analytics\Visits;
use App\Models\AdSpend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Ad performance: which platform a visit came from, spend import, and ROAS per platform, market and campaign. */
class AdPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private array $a;

    private array $mx;

    private int $orderNo = 1001;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.storefront_scheme' => 'http', 'lumee.storefront_port' => null]);
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => 'a@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop A'])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
        Http::fake(['open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['USD' => 1, 'MXN' => 20, 'EUR' => 0.8]])]);
        $this->artisan('lumee:exchange-rates')->assertSuccessful();
        $this->mx = $this->as()->postJson('/api/admin/markets', [
            'name' => 'Mexico', 'handle' => 'mx', 'countries' => ['MX'], 'currency' => 'MXN', 'default_locale' => 'es', 'locales' => [],
            'price_adjustment' => 0, 'rounding' => 'none', 'exchange_rate' => null, 'enabled' => true,
        ])->assertCreated()->json('data');
    }

    private function as(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->a['token'], 'X-Store-Id' => (string) $this->a['store_id'], 'Accept' => 'application/json']);
    }

    /** A visit, optionally with a paid order (total in store currency minor units). */
    private function visit(array $attrs, ?int $total = null, string $visitor = '', ?string $at = null): void
    {
        $orderId = null;
        if ($total !== null) {
            $orderId = DB::table('orders')->insertGetId([
                'store_id' => $this->a['store_id'], 'number' => $this->orderNo++, 'token' => (string) Str::uuid(), 'email' => 'b@example.com', 'currency' => 'USD',
                'subtotal' => $total, 'total' => $total, 'shipping_address' => '{}', 'payment_gateway' => 'test', 'financial_status' => 'paid',
                'exchange_rate' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('analytics_sessions')->insert([
            'store_id' => $this->a['store_id'], 'token' => (string) Str::uuid(), 'visitor' => $visitor ?: (string) Str::uuid(),
            'started_at' => $at ?? now()->subDay(), 'last_seen_at' => $at ?? now()->subDay(), 'source' => 'direct', 'order_id' => $orderId, ...$attrs,
        ]);
    }

    public function test_visits_know_their_ad_platform(): void
    {
        $this->assertSame('google', Visits::adPlatform(['gclid' => 'abc'], ''));
        $this->assertSame('meta', Visits::adPlatform(['fbclid' => 'x'], 'l.facebook.com'));
        $this->assertSame('tiktok', Visits::adPlatform(['ttclid' => 'x'], ''));
        $this->assertSame('meta', Visits::adPlatform(['utm_source' => 'Instagram'], ''));
        $this->assertSame('google', Visits::adPlatform([], 'google.com.mx'));
        $this->assertSame('meta', Visits::adPlatform([], 'instagram.com'));
        $this->assertNull(Visits::adPlatform(['utm_source' => 'newsletter'], 'google.com'));   // tagged: trust the tag
        $this->assertNull(Visits::adPlatform([], 'blog.example.com'));

        // A real storefront visit from a Google ad
        $this->get(self::HOST.'/?gclid=abc&utm_campaign=Spring', ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120'])->assertOk();
        $s = DB::table('analytics_sessions')->where('store_id', $this->a['store_id'])->first();
        $this->assertSame('google', $s->ad_platform);
        $this->assertSame('paid', $s->source);
        $this->assertSame('spring', $s->utm_campaign);
    }

    public function test_spend_import_from_platform_exports(): void
    {
        // Meta Ads Manager: spend in the header currency, one line per day, campaign and country
        $meta = "Reporting starts,Reporting ends,Campaign name,Country,Amount spent (MXN),Impressions,Link clicks\n"
            ."2026-11-20,2026-11-20,Fundas Noviembre,Mexico,\"2,000.00\",15000,320\n"
            ."2026-11-21,2026-11-21,Fundas Noviembre,Mexico,1000,9000,150\n"
            .",,,,3000,24000,470\n";   // totals line
        $r = $this->as()->post('/api/admin/ads/spend/import', ['platform' => 'meta', 'file' => UploadedFile::fake()->createWithContent('meta.csv', $meta)])->assertOk();
        $r->assertJsonPath('imported', 2)->assertJsonPath('markets', 1)->assertJsonPath('from', '2026-11-20');
        $line = AdSpend::withoutGlobalScope('store')->where('date', '2026-11-20')->first();
        $this->assertSame(10000, $line->spend);           // MX$2,000 = US$100
        $this->assertSame(200000, $line->amount);
        $this->assertSame('MXN', $line->currency);
        $this->assertSame($this->mx['id'], $line->market_id);
        $this->assertSame(320, $line->clicks);

        // Same report again: replaced, not doubled
        $this->as()->post('/api/admin/ads/spend/import', ['platform' => 'meta', 'text' => $meta])->assertOk();
        $this->assertSame(2, AdSpend::withoutGlobalScope('store')->count());

        // Google Ads: title lines first, tabs, Chinese headers, currency column
        $google = "广告系列报告\n2026年11月1日 - 2026年11月30日\n日期\t广告系列\t货币代码\t费用\t展示次数\t点击次数\n2026-11-20\tBrand Search\tUSD\t45.50\t1200\t80\n总计\t\t\t45.50\t1200\t80\n";
        $this->as()->post('/api/admin/ads/spend/import', ['platform' => 'google', 'text' => $google])->assertOk()->assertJsonPath('imported', 1);
        $this->assertSame(4550, AdSpend::withoutGlobalScope('store')->where('platform', 'google')->value('spend'));

        $this->as()->post('/api/admin/ads/spend/import', ['platform' => 'tiktok', 'text' => "Campaign,Impressions\nA,10\n"])->assertStatus(422);
    }

    public function test_report_sets_spend_against_attributed_orders(): void
    {
        $day = now()->subDay()->toDateString();
        AdSpend::withoutGlobalScope('store')->insert([
            ['store_id' => $this->a['store_id'], 'date' => $day, 'platform' => 'google', 'market_id' => null, 'campaign' => 'spring', 'spend' => 10000, 'currency' => 'USD', 'amount' => 10000, 'impressions' => 1000, 'clicks' => 50, 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()],
            ['store_id' => $this->a['store_id'], 'date' => $day, 'platform' => 'meta', 'market_id' => $this->mx['id'], 'campaign' => 'fundas', 'spend' => 20000, 'currency' => 'USD', 'amount' => 20000, 'impressions' => 5000, 'clicks' => 100, 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()],
        ]);
        // Google: two paid visits, one bought $500 → ROAS 5
        $this->visit(['source' => 'paid', 'ad_platform' => 'google', 'utm_campaign' => 'spring'], 50000);
        $this->visit(['source' => 'paid', 'ad_platform' => 'google', 'utm_campaign' => 'spring']);
        // Meta (Mexico): a paid visit, then the same visitor came back directly and bought $100 → credited to Meta
        $this->visit(['source' => 'paid', 'ad_platform' => 'meta', 'utm_campaign' => 'fundas', 'market_id' => $this->mx['id']], null, 'v-meta', now()->subDays(2)->toDateTimeString());
        $this->visit(['source' => 'direct', 'market_id' => $this->mx['id']], 10000, 'v-meta', now()->subDay()->toDateTimeString());
        // TikTok organic (a post): 3 orders, no spend
        foreach ([1, 2, 3] as $_) {
            $this->visit(['source' => 'social', 'ad_platform' => 'tiktok'], 2000);
        }

        $r = $this->as()->getJson('/api/admin/ads/report?days=30')->assertOk();
        $p = collect($r->json('platforms'))->keyBy('key');
        $this->assertSame('100.00', $p['google']['spend']);
        $this->assertSame(1, $p['google']['orders']);
        $this->assertSame('500.00', $p['google']['revenue']);
        $this->assertEquals(5, $p['google']['roas']);
        $this->assertEquals(50, $p['google']['conversion']);
        $this->assertSame('2.00', $p['google']['cpc']);
        $this->assertSame(1, $p['meta']['orders']);            // the direct return visit counts for Meta
        $this->assertEquals(0.5, $p['meta']['roas']);
        $this->assertSame(3, $p['tiktok']['organic_orders']);
        $this->assertSame(0, $p['tiktok']['orders']);

        $m = collect($r->json('markets'))->keyBy('key');
        $this->assertEquals(0.5, $m[$this->mx['id']]['roas']);
        $this->assertSame('300.00', $r->json('totals.spend'));
        $this->assertEquals(2, $r->json('totals.roas'));   // 600 / 300

        $insights = implode("\n", $r->json('insights'));
        $this->assertStringContainsString('Mexico', $insights);           // ROAS below 1
        $this->assertStringContainsString('Google 的 ROAS', $insights);   // move budget to the better platform
        $this->assertStringContainsString('TikTok 的自然流量', $insights);

        // Manual entry and delete
        $this->as()->postJson('/api/admin/ads/spend', ['date' => $day, 'platform' => 'tiktok', 'amount' => 25, 'currency' => 'EUR'])->assertCreated();
        $this->assertSame(3125, AdSpend::withoutGlobalScope('store')->where('platform', 'tiktok')->value('spend'));   // €25 = $31.25
        $id = collect($this->as()->getJson('/api/admin/ads/report')->json('spend_lines'))->firstWhere('platform', 'tiktok')['id'];
        $this->as()->deleteJson('/api/admin/ads/spend/'.$id)->assertOk();
        $this->assertSame(2, AdSpend::withoutGlobalScope('store')->count());
    }
}
