<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Themes\ThemeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ThemeTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.default_theme' => 'aurora']);   // these tests exercise the Aurora theme
        Storage::fake('public');
        $this->a = $this->register('a@example.com', 'Shop A');
    }

    private function register(string $email, string $store): array
    {
        $res = $this->postJson('/api/admin/auth/register', [
            'name' => 'M', 'email' => $email, 'password' => 'secret-pass', 'store_name' => $store,
        ])->assertCreated();

        return ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
    }

    private function as(array $m): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$m['token'], 'X-Store-Id' => (string) $m['store_id']]);
    }

    private function shopper(): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this;
    }

    private function draft(array $m): array
    {
        return $this->as($m)->getJson('/api/admin/theme')->assertOk()->json('config');
    }

    public function test_new_store_gets_default_theme_and_preview_url(): void
    {
        $res = $this->as($this->a)->getJson('/api/admin/theme')->assertOk();

        $res->assertJsonPath('theme', 'aurora')
            ->assertJsonPath('config.settings.colors.primary', '#B08A78')
            ->assertJsonPath('config.sections.0.type', 'hero')
            ->assertJsonPath('has_unpublished_changes', false);
        $this->assertStringContainsString('_preview=', $res->json('preview_url'));
        $this->assertArrayHasKey('image_text', $res->json('schema.sections'));

        $this->shopper()->get(self::HOST.'/')->assertOk()->assertSee('New Collection')->assertSee('--rose-gold-500: #B08A78', false);
    }

    public function test_draft_is_sanitized_against_the_schema(): void
    {
        $cfg = $this->draft($this->a);
        $cfg['settings']['colors']['primary'] = 'red; } body { display:none';          // not a hex color
        $cfg['settings']['colors']['text'] = '#112233';
        $cfg['settings']['typography']['heading_font'] = 'Comic Sans';                  // not in options
        $cfg['settings']['header']['announcement_link'] = 'javascript:alert(1)';        // unsafe URL
        $cfg['settings']['header']['logo_height'] = 999;                                // clamped to max
        $cfg['settings']['evil'] = ['x' => 1];                                          // unknown group
        $cfg['sections'][0]['settings']['heading'] = '<script>alert(1)</script>Hello';
        $cfg['sections'][0]['settings']['bogus'] = 'x';                                 // unknown field
        $cfg['sections'][] = ['type' => 'not_a_section', 'settings' => []];             // unknown type
        $cfg['sections'][] = ['type' => 'hero', 'id' => 'h2'];
        $cfg['sections'][] = ['type' => 'hero', 'id' => 'h3'];
        $cfg['sections'][] = ['type' => 'hero', 'id' => 'h4'];                          // over max_per_page (3)

        $saved = $this->as($this->a)->putJson('/api/admin/theme', $cfg)->assertOk()
            ->assertJsonPath('has_unpublished_changes', true)->json('config');

        $this->assertSame('#B08A78', $saved['settings']['colors']['primary']);
        $this->assertSame('#112233', $saved['settings']['colors']['text']);
        $this->assertSame('Cormorant Garamond', $saved['settings']['typography']['heading_font']);
        $this->assertSame('', $saved['settings']['header']['announcement_link']);
        $this->assertSame(80, $saved['settings']['header']['logo_height']);
        $this->assertArrayNotHasKey('evil', $saved['settings']);
        $this->assertArrayNotHasKey('bogus', $saved['sections'][0]['settings']);
        $this->assertSame(['hero', 'featured_products', 'features', 'hero', 'hero'], array_column($saved['sections'], 'type'));

        // Heading text is stored as-is but always escaped when rendered
        $this->as($this->a)->postJson('/api/admin/theme/publish')->assertOk();
        $this->shopper()->get(self::HOST.'/')->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;Hello', false);
    }

    public function test_draft_only_visible_with_valid_preview_token_until_published(): void
    {
        $cfg = $this->draft($this->a);
        $cfg['sections'][0]['settings']['heading'] = 'Winter Sparkle';
        $cfg['settings']['colors']['primary'] = '#336699';
        $previewUrl = $this->as($this->a)->putJson('/api/admin/theme', $cfg)->json('preview_url');
        $token = substr($previewUrl, strpos($previewUrl, '_preview=') + 9);

        // Live store unchanged
        $this->shopper()->get(self::HOST.'/')->assertDontSee('Winter Sparkle');

        // Preview shows the draft and is marked noindex / no-store
        $this->shopper()->get(self::HOST.'/?_preview='.$token)->assertOk()
            ->assertSee('Winter Sparkle')->assertSee('--rose-gold-500: #336699', false)
            ->assertHeader('X-Robots-Tag', 'noindex');

        // Tampered, expired, or another store's token → live version
        $this->shopper()->get(self::HOST.'/?_preview='.$token.'x')->assertDontSee('Winter Sparkle');
        $store = Store::find($this->a['store_id']);
        // Correctly signed (new format) but expired
        $exp = time() - 10;
        $expired = "{$store->id}.aurora.{$exp}.".hash_hmac('sha256', "theme-preview|{$store->id}|aurora|{$exp}", config('app.key'));
        $this->shopper()->get(self::HOST.'/?_preview='.$expired)->assertDontSee('Winter Sparkle');

        $b = $this->register('b@example.com', 'Shop B');
        $bToken = app(ThemeService::class)->previewToken(Store::find($b['store_id']));
        $this->shopper()->get(self::HOST.'/?_preview='.$bToken)->assertDontSee('Winter Sparkle');

        // Publish → live
        $this->as($this->a)->postJson('/api/admin/theme/publish')->assertOk()->assertJsonPath('has_unpublished_changes', false);
        $this->shopper()->get(self::HOST.'/')->assertSee('Winter Sparkle');
    }

    public function test_discard_restores_published_version(): void
    {
        $this->as($this->a)->postJson('/api/admin/theme/publish');
        $cfg = $this->draft($this->a);
        $cfg['sections'] = [];
        $this->as($this->a)->putJson('/api/admin/theme', $cfg)->assertJsonPath('has_unpublished_changes', true);

        $res = $this->as($this->a)->postJson('/api/admin/theme/discard')->assertOk();
        $res->assertJsonPath('has_unpublished_changes', false)->assertJsonCount(3, 'config.sections');
    }

    public function test_theme_images_are_store_scoped(): void
    {
        $upload = fn ($m) => $this->as($m)->post('/api/admin/uploads/media', [
            'file' => UploadedFile::fake()->image('logo.png', 400, 120),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');

        $mine = $upload($this->a);
        $b = $this->register('b@example.com', 'Shop B');
        $theirs = $upload($b);

        $cfg = $this->draft($this->a);
        $cfg['settings']['header']['logo'] = $mine['id'];
        $cfg['sections'][0]['settings']['image'] = $theirs['id'];      // someone else's image
        $saved = $this->as($this->a)->putJson('/api/admin/theme', $cfg)->json('config');

        $this->assertSame($mine['id'], $saved['settings']['header']['logo']);
        $this->assertNull($saved['sections'][0]['settings']['image']);

        $this->as($this->a)->postJson('/api/admin/theme/publish');
        $this->shopper()->get(self::HOST.'/')->assertSee($mine['url'])->assertDontSee($theirs['url']);

        // Store B's theme is untouched
        $this->assertNull($this->draft($b)['settings']['header']['logo']);
    }

    public function test_sections_render_in_order_and_hidden_ones_are_skipped(): void
    {
        $cfg = $this->draft($this->a);
        $text = app(ThemeService::class)->newSection('aurora', 'rich_text', 'story');
        $text['settings']['heading'] = 'Our Atelier';
        $features = $cfg['sections'][2];
        $features['enabled'] = false;
        $cfg['sections'] = [$text, $cfg['sections'][0], $features];
        $this->as($this->a)->putJson('/api/admin/theme', $cfg);
        $this->as($this->a)->postJson('/api/admin/theme/publish');

        $html = $this->shopper()->get(self::HOST.'/')->assertOk()->getContent();
        $this->assertLessThan(strpos($html, 'data-section-id="hero-1"'), strpos($html, 'Our Atelier'));
        $this->assertStringNotContainsString('30-Day Returns', $html);   // hidden features section
        $this->assertStringNotContainsString('data-section-id="featured_products', $html);
    }
}
