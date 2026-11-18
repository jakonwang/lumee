<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Themes\ThemeService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeSwitchTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    /** A style rule only the Fresh layout contains. */
    private const FRESH_MARKER = '--gradient-metal: var(--rose-gold-500);';

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.default_theme' => 'aurora']);   // start on Aurora so switching to Fresh is observable
        $res = $this->postJson('/api/admin/auth/register', [
            'name' => 'M', 'email' => 'a@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop A',
        ])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
        $this->as()->postJson('/api/admin/products', ['title' => 'Linen Tote', 'price' => 35, 'inventory' => 9, 'status' => 'active']);
    }

    private function as(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->a['token'], 'X-Store-Id' => (string) $this->a['store_id']]);
    }

    private function shopper(): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this;
    }

    private function tokenFrom(string $url): string
    {
        return substr($url, strpos($url, '_preview=') + 9);
    }

    public function test_theme_catalog_and_fresh_defaults(): void
    {
        $res = $this->as()->getJson('/api/admin/theme?theme=fresh')->assertOk();

        $res->assertJsonPath('theme', 'fresh')
            ->assertJsonPath('active_theme', 'aurora')
            ->assertJsonPath('has_unpublished_changes', true)          // not live yet
            ->assertJsonPath('config.settings.colors.primary', '#0E8A5F')
            ->assertJsonPath('config.settings.colors.background', '#FFFFFF')
            ->assertJsonPath('config.settings.typography.heading_font', 'Plus Jakarta Sans');
        $this->assertSame(['aurora', 'fresh'], array_column($res->json('themes'), 'key'));
        $this->assertArrayHasKey('collection_list', $res->json('schema.sections'));   // sections inherited

        $this->as()->getJson('/api/admin/theme?theme=nope')->assertNotFound();
    }

    public function test_preview_a_theme_then_publish_to_switch(): void
    {
        $previewUrl = $this->as()->getJson('/api/admin/theme?theme=fresh')->json('preview_url');
        $token = $this->tokenFrom($previewUrl);

        // Live store is still Aurora; preview shows Fresh
        $this->shopper()->get(self::HOST.'/')->assertOk()->assertDontSee(self::FRESH_MARKER, false)->assertSee('--rose-gold-500: #B08A78', false);
        $this->shopper()->get(self::HOST.'/?_preview='.$token)->assertOk()
            ->assertSee(self::FRESH_MARKER, false)
            ->assertSee('--rose-gold-500: #0E8A5F', false)
            ->assertSee('Plus+Jakarta+Sans', false)
            ->assertSee('Linen Tote');                                    // inherited sections render

        // A token signed for Aurora can't be edited to preview Fresh
        $auroraToken = app(ThemeService::class)->previewToken(Store::find($this->a['store_id']), 'aurora');
        $forged = str_replace('.aurora.', '.fresh.', $auroraToken);
        $this->shopper()->get(self::HOST.'/?_preview='.$forged)->assertDontSee(self::FRESH_MARKER, false);

        // Publish Fresh → it becomes the live theme
        $this->as()->postJson('/api/admin/theme/publish', ['theme' => 'fresh'])->assertOk()
            ->assertJsonPath('active_theme', 'fresh')
            ->assertJsonPath('has_unpublished_changes', false);
        $this->assertSame('fresh', Store::find($this->a['store_id'])->theme);
        $this->shopper()->get(self::HOST.'/')->assertSee(self::FRESH_MARKER, false);
        $this->shopper()->get(self::HOST.'/products/linen-tote')->assertOk()->assertSee(self::FRESH_MARKER, false);
        $this->shopper()->get(self::HOST.'/collections/all')->assertOk()->assertSee(self::FRESH_MARKER, false);

        // Editor now defaults to Fresh
        $this->as()->getJson('/api/admin/theme')->assertJsonPath('theme', 'fresh');
    }

    public function test_each_theme_keeps_its_own_settings(): void
    {
        // Customize Aurora and publish it
        $aurora = $this->as()->getJson('/api/admin/theme')->json('config');
        $aurora['sections'][0]['settings']['heading'] = 'Aurora Heading';
        $this->as()->putJson('/api/admin/theme', $aurora);
        $this->as()->postJson('/api/admin/theme/publish');

        // Customize Fresh and switch to it
        $fresh = $this->as()->getJson('/api/admin/theme?theme=fresh')->json('config');
        $fresh['sections'][0]['settings']['heading'] = 'Fresh Heading';
        $this->as()->putJson('/api/admin/theme', [...$fresh, 'theme' => 'fresh'])->assertJsonPath('theme', 'fresh');
        $this->as()->postJson('/api/admin/theme/publish', ['theme' => 'fresh']);
        $this->shopper()->get(self::HOST.'/')->assertSee('Fresh Heading')->assertDontSee('Aurora Heading');

        // Switch back: Aurora's customizations are still there
        $this->as()->postJson('/api/admin/theme/publish', ['theme' => 'aurora']);
        $this->shopper()->get(self::HOST.'/')->assertSee('Aurora Heading')->assertDontSee(self::FRESH_MARKER, false);
    }

    public function test_new_stores_default_to_fresh_existing_stores_keep_their_theme(): void
    {
        // Shop A was created while the default was Aurora
        config(['lumee.default_theme' => 'fresh']);

        $res = $this->postJson('/api/admin/auth/register', [
            'name' => 'N', 'email' => 'new@example.com', 'password' => 'secret-pass', 'store_name' => 'New Shop',
        ])->assertCreated();
        $this->assertSame('fresh', Store::find($res['stores'][0]['id'])->theme);
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$res['token'], 'X-Store-Id' => (string) $res['stores'][0]['id']])
            ->postJson('/api/admin/products', ['title' => 'Cotton Tee', 'price' => 20, 'inventory' => 3, 'status' => 'active']);

        $this->shopper()->get('http://new-shop.lumee.localhost/')->assertOk()
            ->assertSee(self::FRESH_MARKER, false)
            ->assertSee('New Arrivals')
            ->assertSee('/brand/placeholder.svg', false)    // neutral placeholder for the photo-less product
            ->assertDontSee('/brand/logo-mark.png', false);  // not the jewelry logo

        // The existing Aurora store is unchanged
        $this->assertSame('aurora', Store::find($this->a['store_id'])->theme);
        $this->shopper()->get(self::HOST.'/')->assertDontSee(self::FRESH_MARKER, false);
    }

    public function test_checkout_renders_in_fresh_theme(): void
    {
        $this->as()->postJson('/api/admin/theme/publish', ['theme' => 'fresh']);
        $this->shopper()->withoutMiddleware(ValidateCsrfToken::class);

        $variant = \App\Models\ProductVariant::withoutGlobalScope('store')->first();
        $res = $this->post(self::HOST.'/cart/add', ['variant_id' => $variant->id]);
        $cart = $res->getCookie('lumee_cart', false)->getValue();

        $this->withUnencryptedCookie('lumee_cart', $cart)->get(self::HOST.'/cart')->assertOk()->assertSee(self::FRESH_MARKER, false);
        $this->withUnencryptedCookie('lumee_cart', $cart)->get(self::HOST.'/checkout')->assertOk()
            ->assertSee('Complete order')
            ->assertSee(':root { --gradient-metal: var(--rose-gold-500); }', false);   // Fresh checkout overrides
    }
}
