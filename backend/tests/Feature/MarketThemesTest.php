<?php

namespace Tests\Feature;

use App\Models\StoreThemeMarket;
use App\Models\ThemeVersion;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Store design per market: overrides that follow the main site, an own home page, preview, versions, translations. */
class MarketThemesTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private array $a;

    private int $mx;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.storefront_scheme' => 'http', 'lumee.storefront_port' => null]);
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => 'a@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop A'])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
        Http::fake(['open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['USD' => 1, 'MXN' => 20, 'EUR' => 0.9]])]);
        $this->artisan('lumee:exchange-rates')->assertSuccessful();
        $this->mx = $this->as()->postJson('/api/admin/markets', ['name' => 'Mexico', 'handle' => 'mx', 'countries' => ['MX'], 'currency' => 'MXN',
            'default_locale' => 'es', 'locales' => [], 'price_adjustment' => 0, 'rounding' => 'none', 'enabled' => true])->assertCreated()->json('data.id');
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

    private function editor(?int $market = null): array
    {
        return $this->as()->getJson('/api/admin/theme'.($market ? "?market=$market" : ''))->assertOk()->json();
    }

    private function save(array $d, array $config, ?int $market = null, bool $own = false): array
    {
        return $this->as()->putJson('/api/admin/theme', [...$config, 'id' => $d['id'], 'market' => $market, 'own_sections' => $own])->assertOk()->json();
    }

    private function publish(array $d, ?int $market = null): void
    {
        $this->as()->postJson('/api/admin/theme/publish', ['id' => $d['id'], 'market' => $market])->assertOk();
    }

    public function test_market_overrides_follow_the_main_site_for_everything_else(): void
    {
        $main = $this->editor();
        $this->publish($main);
        $d = $this->editor($this->mx);
        $this->assertSame(['id' => $this->mx, 'name' => 'Mexico', 'locale' => 'es', 'own_sections' => false], $d['market']);
        $this->assertSame($main['config'], $d['config']);   // nothing changed yet: same as the main site
        $this->assertFalse($d['has_unpublished_changes']);
        $this->assertStringContainsString('/es-mx/?_preview=', $d['preview_url']);

        // Mexico changes the announcement and the hero heading, and hides the features block
        $c = $d['config'];
        $c['settings']['header']['announcement_text'] = 'Envío gratis en todo México';
        $hero = collect($c['sections'])->search(fn ($s) => $s['type'] === 'hero');
        $features = collect($c['sections'])->search(fn ($s) => $s['type'] === 'features');
        $c['sections'][$hero]['settings']['heading'] = 'Joyas para todos los días';
        $c['sections'][$features]['enabled'] = false;
        $r = $this->save($d, $c, $this->mx);
        $this->assertTrue($r['has_unpublished_changes']);
        $layer = StoreThemeMarket::withoutGlobalScope('store')->sole()->draft;
        $this->assertSame(['header' => ['announcement_text' => 'Envío gratis en todo México']], $layer['settings']);   // only what differs
        $this->assertSame(['heading' => 'Joyas para todos los días'], $layer['section_overrides'][$c['sections'][$hero]['id']]);
        $this->assertSame(['enabled' => false], $layer['section_overrides'][$c['sections'][$features]['id']]);
        $this->assertNull($layer['sections']);

        // Draft: the preview shows it, the live site doesn't yet
        $this->front()->get(self::HOST.'/es-mx')->assertOk()->assertDontSee('Joyas para todos los días');
        $preview = parse_url($r['preview_url']);
        $this->front()->get(self::HOST.$preview['path'].'?'.$preview['query'])->assertOk()->assertSee('Joyas para todos los días');
        $this->publish($d, $this->mx);
        $mx = $this->front()->get(self::HOST.'/es-mx')->assertOk();
        $mx->assertSee('Envío gratis en todo México')->assertSee('Joyas para todos los días')->assertDontSee('Free Shipping');   // features block hidden
        $this->front()->get(self::HOST.'/')->assertOk()->assertDontSee('Envío gratis en todo México')->assertDontSee('Joyas para todos los días');

        // The main site changes its colour: Mexico follows (it never changed it); its own texts stay
        $mc = $main['config'];
        $mc['settings']['colors']['primary'] = '#123456';
        $this->save($main, $mc);
        $this->publish($main);
        $this->front()->get(self::HOST.'/es-mx')->assertSee('#123456', false)->assertSee('Joyas para todos los días');

        // Putting a field back to the main site's value drops the override
        $d = $this->editor($this->mx);
        $this->assertSame('#123456', $d['base']['settings']['colors']['primary']);
        $c = $d['config'];
        $c['settings']['header']['announcement_text'] = $d['base']['settings']['header']['announcement_text'];
        $this->save($d, $c, $this->mx);
        $this->assertSame([], StoreThemeMarket::withoutGlobalScope('store')->sole()->draft['settings']);
        $this->assertTrue(collect($this->editor()['markets'])->firstWhere('id', $this->mx)['customized']);
    }

    public function test_a_market_can_have_its_own_home_page(): void
    {
        $d = $this->editor($this->mx);
        $c = $d['config'];
        $c['sections'] = [['id' => 'promo-1', 'type' => 'rich_text', 'enabled' => true, 'settings' => ['heading' => 'Hot Sale México', 'text' => 'Hasta 50% de descuento']]];
        $r = $this->save($d, $c, $this->mx, own: true);
        $this->assertTrue($r['market']['own_sections']);
        $this->publish($d, $this->mx);
        $this->front()->get(self::HOST.'/es-mx')->assertOk()->assertSee('Hot Sale México')->assertDontSee('New Collection');
        $this->front()->get(self::HOST.'/')->assertOk()->assertDontSee('Hot Sale México');

        // Sections added on the main site don't appear on Mexico's own home page
        $main = $this->editor();
        $mc = $main['config'];
        $mc['sections'][] = ['id' => 'faq-9', 'type' => 'faq', 'enabled' => true, 'settings' => ['heading' => 'Questions?']];
        $this->save($main, $mc);
        $this->publish($main);
        $this->front()->get(self::HOST.'/es-mx')->assertDontSee('Questions?');

        // Back to following the main site
        $d = $this->editor($this->mx);
        $this->save($d, $d['base'], $this->mx, own: false);
        $this->publish($d, $this->mx);
        $this->front()->get(self::HOST.'/es-mx')->assertSee('Questions?')->assertDontSee('Hot Sale México');
    }

    public function test_versions_are_kept_and_can_be_restored(): void
    {
        $d = $this->editor($this->mx);
        foreach (['Primera', 'Segunda'] as $text) {
            $c = $d['config'];
            $c['settings']['header']['announcement_text'] = $text;
            $this->save($d, $c, $this->mx);
            $this->publish($d, $this->mx);
        }
        $versions = $this->as()->getJson("/api/admin/theme/versions?id={$d['id']}&market={$this->mx}")->assertOk()->json('data');
        $this->assertCount(2, $versions);
        $this->assertSame(['publish', 'M'], [$versions[0]['source'], $versions[0]['by']]);
        $this->assertCount(0, $this->as()->getJson("/api/admin/theme/versions?id={$d['id']}")->json('data'));   // the main site's are separate

        $r = $this->as()->postJson("/api/admin/theme/versions/{$versions[1]['id']}/restore")->assertOk()->json();
        $this->assertSame('Primera', $r['config']['settings']['header']['announcement_text']);
        $this->assertTrue($r['has_unpublished_changes']);
        $this->front()->get(self::HOST.'/es-mx')->assertSee('Segunda');   // still live until published

        // Another store can't see or restore them
        $b = $this->postJson('/api/admin/auth/register', ['name' => 'B', 'email' => 'b@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop B'])->json();
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$b['token'], 'X-Store-Id' => (string) $b['stores'][0]['id']])
            ->postJson("/api/admin/theme/versions/{$versions[1]['id']}/restore")->assertNotFound();
        $this->assertSame(2, ThemeVersion::withoutGlobalScope('store')->where('market_id', $this->mx)->count());
    }

    public function test_market_texts_are_not_replaced_by_theme_translations(): void
    {
        $this->as()->putJson('/api/admin/settings/languages', ['default' => 'en', 'published' => ['es']])->assertOk();
        $fields = collect($this->as()->getJson('/api/admin/translations?type=theme&locale=es')->json('data.0.fields'));
        $hero = $fields->first(fn ($f) => str_starts_with($f['key'], 'sections.') && str_ends_with($f['key'], '.text'));
        $this->as()->putJson("/api/admin/translations/theme/{$this->a['store_id']}", ['locale' => 'es', 'fields' => [$hero['key'] => 'Texto traducido del sitio principal']])->assertOk();
        $this->front()->get(self::HOST.'/es-mx')->assertSee('Texto traducido del sitio principal');   // translation used while Mexico follows

        $d = $this->editor($this->mx);
        $c = $d['config'];
        $i = collect($c['sections'])->search(fn ($s) => $s['type'] === 'hero');
        $c['sections'][$i]['settings']['text'] = 'Texto propio de México';
        $this->save($d, $c, $this->mx);
        $this->publish($d, $this->mx);
        $this->front()->get(self::HOST.'/es-mx')->assertSee('Texto propio de México')->assertDontSee('Texto traducido del sitio principal');
        $this->front()->get(self::HOST.'/es')->assertSee('Texto traducido del sitio principal');   // Spanish on the main site still translated
    }

    public function test_arabic_market_is_right_to_left_with_its_script_font(): void
    {
        $this->as()->postJson('/api/admin/markets', ['name' => 'UAE', 'handle' => 'ae', 'countries' => ['AE'], 'currency' => 'AED', 'exchange_rate' => 3.6725,
            'default_locale' => 'ar', 'locales' => ['en'], 'price_adjustment' => 0, 'rounding' => 'none', 'enabled' => true])->assertCreated();
        $this->as()->postJson('/api/admin/products', ['title' => 'Rose Ring', 'price' => 40, 'inventory' => 5, 'status' => 'active'])->assertCreated();

        $ar = $this->front()->get(self::HOST.'/ar-ae/products/rose-ring')->assertOk();
        $ar->assertSee('<html lang="ar" dir="rtl">', false)
            ->assertSee('أضف إلى الحقيبة')                       // "Add to bag" in Arabic
            ->assertSee('Noto+Sans+Arabic', false)               // script font loaded…
            ->assertSee("'Noto Sans Arabic'", false)             // …and in the font stack
            ->assertSee('146.90');                               // Western digits: 40 × 3.6725
        $this->assertStringContainsString('ar-AE-u-nu-latn', $ar->getContent());   // the same format in the browser

        $this->front()->get(self::HOST.'/en-ae/products/rose-ring')->assertSee('dir="ltr"', false)->assertDontSee('Noto+Sans+Arabic', false);
        $this->front()->get(self::HOST.'/products/rose-ring')->assertSee('dir="ltr"', false);
        $this->front()->get(self::HOST.'/ar-ae/cart')->assertOk()->assertSee('dir="rtl"', false)->assertSee('حقيبتك فارغة');

        // Arabic plurals use all six forms
        app()->setLocale('ar');
        $this->assertSame('منتجان', trans_choice(':count product|:count products', 2));
        $this->assertSame('11 منتجًا', trans_choice(':count product|:count products', 11));
    }

    public function test_market_changes_blocks_on_page_templates_too(): void
    {
        $this->as()->postJson('/api/admin/products', ['title' => 'Rose Ring', 'price' => 40, 'inventory' => 5, 'status' => 'active'])->assertCreated();
        $main = $this->editor();
        $c = $main['config'];
        $c['templates']['product'][] = ['id' => 'pfaq', 'type' => 'faq', 'enabled' => true, 'settings' => ['heading' => 'Questions', 'q1' => 'Silver?', 'a1' => 'Yes']];
        $this->save($main, $c);
        $this->publish($main);

        $d = $this->editor($this->mx);
        $mc = $d['config'];
        $i = collect($mc['templates']['product'])->search(fn ($s) => $s['id'] === 'pfaq');
        $mc['templates']['product'][$i]['settings']['heading'] = 'Preguntas frecuentes';
        $mc['templates']['product'][] = ['id' => 'extra', 'type' => 'rich_text', 'enabled' => true, 'settings' => ['heading' => 'Not allowed in a market']];
        $this->save($d, $mc, $this->mx);
        $this->publish($d, $this->mx);
        $layer = StoreThemeMarket::withoutGlobalScope('store')->sole()->published;
        $this->assertSame(['pfaq' => ['heading' => 'Preguntas frecuentes']], $layer['section_overrides']);   // structure follows the main site

        $this->front()->get(self::HOST.'/es-mx/products/rose-ring')->assertOk()->assertSee('Preguntas frecuentes')->assertDontSee('Not allowed in a market');
        $this->front()->get(self::HOST.'/products/rose-ring')->assertOk()->assertSee('Questions')->assertDontSee('Preguntas frecuentes');
    }

    public function test_bad_input_and_other_stores_markets(): void
    {
        $d = $this->editor($this->mx);
        $c = $d['config'];
        $c['settings']['colors']['primary'] = 'javascript:alert(1)';
        $c['sections'][0]['settings']['button_link'] = 'javascript:alert(1)';
        $this->save($d, $c, $this->mx);
        $layer = StoreThemeMarket::withoutGlobalScope('store')->sole()->draft;
        $this->assertSame([], $layer['settings']);            // invalid colour → the theme's value → no override
        $this->assertSame([], $layer['section_overrides']);

        $b = $this->postJson('/api/admin/auth/register', ['name' => 'B', 'email' => 'b@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop B'])->json();
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$b['token'], 'X-Store-Id' => (string) $b['stores'][0]['id'], 'Accept' => 'application/json'])
            ->getJson("/api/admin/theme?market={$this->mx}")->assertNotFound();
    }
}
