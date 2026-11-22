<?php

namespace Tests\Feature;

use App\Models\ProductImage;
use App\Models\StoreMedia;
use App\Models\StoreThemeMarket;
use App\Models\ThemeSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Phase C: automatic store design per market, the design check, campaign schedules from the shopping calendar. */
class AutoDesignTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-20 10:00:00');   // a week before Black Friday 2026 (27 Nov)
        \Illuminate\Support\Carbon::setTestNow('2026-11-20 10:00:00');
        Storage::fake(config('lumee.media_disk'));
        config(['lumee.storefront_scheme' => 'http', 'lumee.storefront_port' => null]);
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => 'a@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop A'])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
        \App\Models\Store::whereKey($this->a['store_id'])->update(['trial_ends_at' => now()->addYears(2)]);   // time travel below mustn't end the trial
        Http::fake(['open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['USD' => 1, 'MXN' => 20, 'EUR' => 0.9, 'BRL' => 5]])]);
        $this->artisan('lumee:exchange-rates')->assertSuccessful();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    private function as(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->a['token'], 'X-Store-Id' => (string) $this->a['store_id'], 'Accept' => 'application/json']);
    }

    private function front(): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        return $this;
    }

    private function market(string $name, string $handle, string $country, string $currency, string $locale): int
    {
        return $this->as()->postJson('/api/admin/markets', ['name' => $name, 'handle' => $handle, 'countries' => [$country], 'currency' => $currency,
            'default_locale' => $locale, 'locales' => [], 'price_adjustment' => 0, 'rounding' => 'none', 'enabled' => true])->assertCreated()->json('data.id');
    }

    /** A product with a real (fake-disk) photo, so the banner composer has something to use. */
    private function product(string $title): int
    {
        $id = $this->as()->postJson('/api/admin/products', ['title' => $title, 'price' => 40, 'inventory' => 10, 'status' => 'active'])->json('data.id');
        $im = imagecreatetruecolor(400, 400);
        imagefill($im, 0, 0, imagecolorallocate($im, 240, 200, 210));
        ob_start();
        imagepng($im);
        $path = "stores/{$this->a['store_id']}/products/$title.png";
        Storage::disk(config('lumee.media_disk'))->put($path, ob_get_clean());
        ProductImage::withoutGlobalScope('store')->forceCreate(['store_id' => $this->a['store_id'], 'product_id' => $id, 'disk' => config('lumee.media_disk'), 'path' => $path, 'position' => 0]);

        return $id;
    }

    private function freeShippingOver(int $dollars): void
    {
        // New stores get "Free Standard Shipping" over $99 (StoreProvisioner); move the threshold
        \App\Models\ShippingRate::withoutGlobalScope('store')->where('store_id', $this->a['store_id'])->where('price', 0)->update(['min_subtotal' => $dollars * 100]);
    }

    public function test_mexico_gets_a_latam_design_in_spanish_with_real_numbers(): void
    {
        $this->product('Rose Ring');
        $this->product('Gold Hoops');
        $this->freeShippingOver(49);
        $mx = $this->market('Mexico', 'mx', 'MX', 'MXN', 'es');
        $theme = $this->as()->getJson('/api/admin/theme')->json('id');

        $r = $this->as()->postJson('/api/admin/theme/generate', ['id' => $theme, 'market' => $mx])->assertOk()->json();
        $this->assertSame('latam', $r['region']);
        $this->assertTrue($r['market']['own_sections']);
        $this->assertNotEmpty($r['notes']);
        $types = array_column($r['config']['sections'], 'type');
        $this->assertSame('slideshow', $types[0]);
        $this->assertContains('marquee', $types);
        $this->assertNotContains('flash_sale', $types);   // nothing on sale, no running promotion → skipped
        $this->assertSame('pill', $r['config']['settings']['style']['buttons']);   // the LatAm kit
        $this->assertSame('Envío gratis desde $980.00 · Devoluciones en 30 días', $r['config']['settings']['header']['announcement_text']);   // $49 × 20, in pesos
        $slide = $r['config']['sections'][0]['settings'];
        $this->assertSame('Regalos de Navidad', $slide['slide1_heading']);   // Christmas is Mexico's next event in its preparation window
        $this->assertNotNull($slide['slide1_image']);
        $this->assertSame(2, StoreMedia::withoutGlobalScope('store')->count());   // banner for computers and phones
        $this->assertSame([], array_values(array_filter($r['issues'], fn ($i) => $i['level'] === 'error')));

        // A draft: the Mexico site doesn't change until it's published; the main site never does
        $this->front()->get(self::HOST.'/es-mx')->assertOk()->assertDontSee('Envío gratis desde $980.00');
        $this->as()->postJson('/api/admin/theme/publish', ['id' => $theme, 'market' => $mx])->assertOk();
        $this->front()->get(self::HOST.'/es-mx')->assertOk()->assertSee('Envío gratis desde $980.00')->assertSee('Regalos de Navidad')->assertSee('Comprar ahora');
        $this->front()->get(self::HOST.'/')->assertOk()->assertDontSee('Envío gratis desde');
    }

    public function test_design_check_catches_wrong_numbers_language_contrast_and_links(): void
    {
        $this->product('Rose Ring');
        $hidden = $this->as()->postJson('/api/admin/products', ['title' => 'Draft Ring', 'price' => 10, 'status' => 'draft'])->json('data.handle');
        $this->freeShippingOver(49);
        $br = $this->market('Brasil', 'br', 'BR', 'BRL', 'pt');
        $d = $this->as()->getJson("/api/admin/theme?market=$br")->json();
        $c = $d['config'];
        $c['settings']['header']['announcement_text'] = 'Frete grátis acima de R$ 99,00';   // real threshold: R$ 245,00
        $c['settings']['product']['perks'] = "Trocas em até 60 dias";                    // returns window: 30 days
        $c['settings']['colors']['text'] = '#F4F4F4';                                       // on a white background
        $hero = collect($c['sections'])->search(fn ($s) => $s['type'] === 'hero');
        $c['sections'][$hero]['settings']['button_link'] = "/products/$hidden";
        $this->as()->putJson('/api/admin/theme', [...$c, 'id' => $d['id'], 'market' => $br])->assertOk();

        $issues = collect($this->as()->getJson("/api/admin/theme/check?id={$d['id']}&market=$br")->assertOk()->json('issues'));
        $codes = $issues->pluck('code')->all();
        foreach (['free_shipping_amount', 'returns_days', 'contrast_text', 'link_not_sold', 'untranslated'] as $code) {
            $this->assertContains($code, $codes, "missing $code");
        }
        $this->assertSame('error', $issues->firstWhere('code', 'free_shipping_amount')['level']);
        $this->assertStringContainsString('245.00', $issues->firstWhere('code', 'free_shipping_amount')['message']);
        $this->assertSame('warning', $issues->firstWhere('code', 'untranslated')['level']);   // the English main-site texts on a Portuguese site
        $this->assertSame('error', $issues->first()['level']);   // errors first

        // A European market is reminded to say prices include VAT
        $de = $this->market('Deutschland', 'de', 'DE', 'EUR', 'de');
        $this->assertContains('eu_vat', collect($this->as()->getJson("/api/admin/theme/check?id={$d['id']}&market=$de")->json('issues'))->pluck('code')->all());
    }

    public function test_campaign_version_goes_live_and_comes_down_on_time(): void
    {
        $this->product('Rose Ring');
        $d = $this->as()->getJson('/api/admin/theme')->json();
        $this->as()->postJson('/api/admin/theme/publish', ['id' => $d['id']])->assertOk();

        $list = $this->as()->getJson("/api/admin/theme/schedules?id={$d['id']}")->assertOk()->json();
        $bf = collect($list['events'])->firstWhere('key', 'black_friday-2026');
        $this->assertTrue($bf['preparing']);
        $this->assertNull($bf['schedule_id']);

        $s = $this->as()->postJson('/api/admin/theme/schedules', ['id' => $d['id'], 'event_key' => 'black_friday-2026'])->assertCreated()->json('data');
        $this->assertSame('scheduled', $s['status']);
        $this->as()->postJson('/api/admin/theme/schedules', ['id' => $d['id'], 'name' => 'Overlap', 'starts_at' => '2026-11-27 12:00', 'ends_at' => '2026-11-28 12:00'])
            ->assertJsonValidationErrors('starts_at');

        $this->artisan('lumee:theme-schedules')->assertSuccessful();
        $this->front()->get(self::HOST.'/')->assertDontSee('Our biggest sale of the year');

        $this->travelTo('2026-11-27 09:00:00');
        $this->artisan('lumee:theme-schedules')->assertSuccessful();
        $this->assertSame('active', ThemeSchedule::withoutGlobalScope('store')->find($s['id'])->status);
        $this->front()->get(self::HOST.'/')->assertOk()->assertSee('Black Friday')->assertSee('Our biggest sale of the year');

        $this->travelTo('2026-11-28 08:00:00');
        $this->artisan('lumee:theme-schedules')->assertSuccessful();
        $this->assertSame('done', ThemeSchedule::withoutGlobalScope('store')->find($s['id'])->status);
        $this->front()->get(self::HOST.'/')->assertOk()->assertDontSee('Our biggest sale of the year');
        $this->assertSame(['publish', 'schedule', 'schedule'], \App\Models\ThemeVersion::withoutGlobalScope('store')->orderBy('id')->pluck('source')->all());
    }

    public function test_a_publish_during_the_campaign_wins_and_cancel_takes_it_down(): void
    {
        $this->product('Rose Ring');
        $mx = $this->market('Mexico', 'mx', 'MX', 'MXN', 'es');
        $d = $this->as()->getJson("/api/admin/theme?market=$mx")->json();
        $s = $this->as()->postJson('/api/admin/theme/schedules', ['id' => $d['id'], 'market' => $mx, 'event_key' => 'christmas-2026'])->json('data');

        $this->travelTo(CarbonImmutable::parse($s['starts_at'])->addHour());
        $this->artisan('lumee:theme-schedules');
        $this->front()->get(self::HOST.'/es-mx')->assertSee('Regalos de Navidad');
        $this->as()->postJson("/api/admin/theme/schedules/{$s['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->front()->get(self::HOST.'/es-mx')->assertDontSee('Regalos de Navidad');

        // Another campaign; the merchant publishes their own change while it runs → kept at the end
        $s2 = $this->as()->postJson('/api/admin/theme/schedules', ['id' => $d['id'], 'market' => $mx, 'name' => 'Weekend', 'starts_at' => now()->addHour()->toDateTimeString(), 'ends_at' => now()->addDays(2)->toDateTimeString()])->assertCreated()->json('data');
        $this->travelTo(now()->addHours(2));
        $this->artisan('lumee:theme-schedules');
        $c = $this->as()->getJson("/api/admin/theme?market=$mx")->json('config');
        $c['settings']['header']['announcement_text'] = 'Mi propio anuncio';
        $this->as()->putJson('/api/admin/theme', [...$c, 'id' => $d['id'], 'market' => $mx])->assertOk();
        $this->as()->postJson('/api/admin/theme/publish', ['id' => $d['id'], 'market' => $mx])->assertOk();
        $this->travelTo(now()->addDays(3));
        $this->artisan('lumee:theme-schedules');
        $this->assertSame('done', ThemeSchedule::withoutGlobalScope('store')->find($s2['id'])->status);
        $this->front()->get(self::HOST.'/es-mx')->assertSee('Mi propio anuncio');
    }

    public function test_auto_campaigns_wait_for_review(): void
    {
        $this->product('Rose Ring');
        $this->market('Mexico', 'mx', 'MX', 'MXN', 'es');
        $r = $this->as()->putJson('/api/admin/theme/auto-campaigns', ['enabled' => true])->assertOk()->json();
        $this->assertGreaterThan(0, $r['prepared']);
        $pending = ThemeSchedule::withoutGlobalScope('store')->where('status', 'review')->get();
        $this->assertSame($r['prepared'], $pending->count());
        $this->assertContains('black_friday-2026', $pending->pluck('event_key')->all());
        $this->assertSame($pending->count(), collect($this->as()->getJson('/api/admin/dashboard')->json('todos'))->firstWhere('key', 'campaign_designs')['count']);

        // Not approved → never goes live; approved → it does
        $bf = $pending->firstWhere('event_key', 'black_friday-2026');
        $this->travelTo('2026-11-27 09:00:00');
        $this->artisan('lumee:theme-schedules');
        $this->assertSame('review', $bf->fresh()->status);
        $this->as()->postJson("/api/admin/theme/schedules/{$bf->id}/approve")->assertOk()->assertJsonPath('data.status', 'scheduled');
        $this->artisan('lumee:theme-schedules');
        $this->assertSame('active', $bf->fresh()->status);

        // The daily job makes nothing twice
        $this->artisan('lumee:theme-schedules --prepare')->assertSuccessful();
        $this->assertSame(1, ThemeSchedule::withoutGlobalScope('store')->where('event_key', 'black_friday-2026')->whereNull('market_id')->count());
        $this->assertSame(0, StoreThemeMarket::withoutGlobalScope('store')->whereNotNull('draft')->where('draft', 'like', '%Black Friday%')->count());   // drafts untouched
    }
}
