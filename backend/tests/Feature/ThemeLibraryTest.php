<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Store;
use App\Models\StoreMedia;
use App\Models\StoreTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class ThemeLibraryTest extends TestCase
{
    use RefreshDatabase;

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('lumee.media_disk'));
        $this->a = $this->register('a@example.com', 'Shop A');
        $this->b = $this->register('b@example.com', 'Shop B');
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

    private function zip(array $files): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'tz').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return new UploadedFile($path, 'dawn-custom.zip', 'application/zip', null, true);
    }

    public function test_library_copy_rename_publish_and_delete_rules(): void
    {
        $list = $this->as($this->a)->getJson('/api/admin/themes')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.live', true)->assertJsonCount(2, 'templates');
        $liveId = $list->json('data.0.id');

        // Edit the live theme's draft, then copy it: the copy carries the draft, is not live
        $config = $this->as($this->a)->getJson('/api/admin/theme')->json('config');
        $config['settings']['colors']['primary'] = '#112233';
        $this->as($this->a)->putJson('/api/admin/theme', $config)->assertOk();
        $copy = $this->as($this->a)->postJson('/api/admin/themes', ['copy_of' => $liveId])->assertCreated()
            ->assertJsonPath('data.live', false)->json('data');
        $this->assertSame('#112233', $copy['swatches'][0]);

        $this->as($this->a)->patchJson("/api/admin/themes/{$copy['id']}", ['name' => 'Holiday'])->assertJsonPath('data.name', 'Holiday');
        $this->as($this->a)->postJson('/api/admin/themes', ['theme' => 'aurora'])->assertCreated()->assertJsonPath('data.source', 'template');

        // Editor works on a library theme by id; publishing it makes it live
        $this->as($this->a)->getJson("/api/admin/theme?id={$copy['id']}")->assertJsonPath('live', false)->assertJsonPath('name', 'Holiday');
        $this->as($this->a)->postJson('/api/admin/theme/publish', ['id' => $copy['id']])->assertJsonPath('live', true);
        $this->assertSame($copy['id'], Store::find($this->a['store_id'])->theme_id);
        $this->as($this->a)->getJson('/api/admin/theme')->assertJsonPath('id', $copy['id']);

        // Live theme can't be deleted; the old one can
        $this->as($this->a)->deleteJson("/api/admin/themes/{$copy['id']}")->assertStatus(422);
        $this->as($this->a)->deleteJson("/api/admin/themes/{$liveId}")->assertNoContent();

        // Other stores can't touch it
        $this->as($this->b)->getJson("/api/admin/theme?id={$copy['id']}")->assertNotFound();
        $this->as($this->b)->getJson("/api/admin/themes/{$copy['id']}/export")->assertNotFound();
    }

    public function test_preview_token_for_a_library_theme(): void
    {
        $id = $this->as($this->a)->postJson('/api/admin/themes', ['theme' => 'aurora'])->json('data.id');
        $url = $this->as($this->a)->getJson("/api/admin/theme?id={$id}")->json('preview_url');
        $this->assertStringContainsString('_preview='.$this->a['store_id'].".r{$id}.", $url);

        $store = Store::find($this->a['store_id']);
        $service = app(\App\Themes\ThemeService::class);
        $token = substr($url, strpos($url, '_preview=') + 9);
        $this->assertSame("r{$id}", $service->verifyPreviewToken($store, $token));
        $this->assertNull($service->verifyPreviewToken(Store::find($this->b['store_id']), $token));
    }

    public function test_export_then_import_into_another_store_with_images_and_collections(): void
    {
        // Store A: upload a hero image, pick a collection, export
        $media = $this->as($this->a)->post('/api/admin/uploads/media', ['file' => UploadedFile::fake()->image('hero.jpg', 1200, 600)])->json('data.id');
        $colA = $this->as($this->a)->postJson('/api/admin/collections', ['title' => 'Summer', 'handle' => 'summer'])->json('data.id');
        $config = $this->as($this->a)->getJson('/api/admin/theme')->json('config');
        $config['sections'][0]['settings']['image'] = $media;
        $config['sections'][0]['settings']['heading'] = 'Exported hero';
        $config['sections'][1]['settings']['collection'] = $colA;
        $this->as($this->a)->putJson('/api/admin/theme', $config)->assertOk();
        $id = $this->as($this->a)->getJson('/api/admin/themes')->json('data.0.id');

        $res = $this->as($this->a)->get("/api/admin/themes/{$id}/export")->assertOk();
        $this->assertStringContainsString('lumee-theme.zip', $res->headers->get('content-disposition'));
        $zipPath = $res->baseResponse->getFile()->getPathname();
        $copy = tempnam(sys_get_temp_dir(), 'exp').'.zip';
        copy($zipPath, $copy);

        $zip = new ZipArchive;
        $zip->open($copy);
        $pkg = json_decode($zip->getFromName('theme.json'), true);
        $zip->close();
        $this->assertSame('lumee-theme', $pkg['format']);
        $this->assertSame('summer', $pkg['collections'][(string) $colA]);

        // Store B has its own "summer" collection
        $colB = $this->as($this->b)->postJson('/api/admin/collections', ['title' => 'Summer B', 'handle' => 'summer'])->json('data.id');
        $imp = $this->as($this->b)->post('/api/admin/themes/import', ['file' => new UploadedFile($copy, 'pkg.zip', 'application/zip', null, true)])
            ->assertCreated()->assertJsonPath('data.live', false)->assertJsonPath('data.source', 'import')->assertJsonPath('report.images', 1);

        $theme = StoreTheme::withoutGlobalScope('store')->find($imp->json('data.id'));
        $this->assertSame($this->b['store_id'], $theme->store_id);
        $hero = $theme->draft['sections'][0]['settings'];
        $this->assertSame('Exported hero', $hero['heading']);
        $newMedia = StoreMedia::withoutGlobalScope('store')->find($hero['image']);
        $this->assertSame($this->b['store_id'], $newMedia->store_id);
        $this->assertNotSame($media, $newMedia->id);
        $this->assertSame($colB, $theme->draft['sections'][1]['settings']['collection']);

        // Store B's live theme is untouched
        $this->as($this->b)->getJson('/api/admin/theme')->assertJsonPath('live', true)->assertJsonMissing(['heading' => 'Exported hero']);
    }

    public function test_shopify_theme_is_converted(): void
    {
        $this->as($this->a)->postJson('/api/admin/collections', ['title' => 'Summer', 'handle' => 'summer']);
        $settings = "/*\n * IMPORTANT: The contents of this file are auto-generated.\n */\n".json_encode(['current' => [
            'color_schemes' => [
                'scheme-1' => ['settings' => ['background' => '#FAFAF5', 'text' => '#222222', 'button' => '#C0392B']],
                'scheme-4' => ['settings' => ['background' => '#FFD166']],
            ],
            'sale_badge_color_scheme' => 'scheme-4',
            'type_header_font' => 'poppins_n6',
            'type_body_font' => 'assistant_n4',
        ]]);
        $index = json_encode([
            'sections' => [
                'banner' => ['type' => 'image-banner', 'settings' => ['image' => 'shopify://shop_images/hero.jpg', 'image_height' => 'large'],
                    'blocks' => ['h' => ['type' => 'heading', 'settings' => ['heading' => 'Summer <em>sale</em>']],
                        't' => ['type' => 'text', 'settings' => ['text' => '<p>Up to 50% off</p>']],
                        'b' => ['type' => 'buttons', 'settings' => ['button_label_1' => 'Shop now', 'button_link_1' => 'shopify://collections/summer']]],
                    'block_order' => ['h', 't', 'b']],
                'featured' => ['type' => 'featured-collection', 'settings' => ['title' => 'Bestsellers', 'collection' => 'summer', 'products_to_show' => 6, 'columns_desktop' => 3]],
                'liquid' => ['type' => 'custom-liquid', 'settings' => ['custom_liquid' => '{{ shop.name }}']],
                'cols' => ['type' => 'multicolumn', 'settings' => ['title' => 'Why us'],
                    'blocks' => ['c1' => ['type' => 'column', 'settings' => ['title' => 'Fast', 'text' => '<p>2-day shipping</p>']],
                        'c2' => ['type' => 'column', 'settings' => ['title' => 'Safe', 'text' => '<p>Secure pay</p>']]],
                    'block_order' => ['c1', 'c2']],
                'faq' => ['type' => 'collapsible-content', 'disabled' => true, 'settings' => ['heading' => 'FAQ'],
                    'blocks' => ['r' => ['type' => 'collapsible_row', 'settings' => ['heading' => 'Returns?', 'row_content' => '<p>30 days.</p>']]]],
                'vid' => ['type' => 'video', 'settings' => ['heading' => 'Watch', 'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']],
            ],
            'order' => ['banner', 'featured', 'liquid', 'cols', 'faq', 'vid'],
        ]);
        $header = json_encode(['sections' => ['announcement' => ['type' => 'announcement-bar',
            'blocks' => ['a' => ['type' => 'announcement', 'settings' => ['text' => 'Free shipping over $50', 'link' => 'shopify://pages/shipping']]]]]]);

        $res = $this->as($this->a)->post('/api/admin/themes/import', [
            'base' => 'fresh',
            'file' => $this->zip(['dawn/config/settings_data.json' => $settings, 'dawn/templates/index.json' => $index,
                'dawn/sections/header-group.json' => $header, 'dawn/layout/theme.liquid' => '<html>{{ content_for_layout }}</html>']),
        ])->assertCreated()->assertJsonPath('data.source', 'shopify')->assertJsonPath('data.theme', 'fresh')
            ->assertJsonPath('data.name', 'dawn-custom (Shopify)');

        $report = $res->json('report');
        $this->assertSame(['hero', 'featured_products', null, 'features', 'faq', 'video'], array_column($report['sections'], 'to'));
        $this->assertStringContainsString('1', implode(' ', $report['notes']));   // 1 Shopify image not imported

        $draft = StoreTheme::withoutGlobalScope('store')->find($res->json('data.id'))->draft;
        $this->assertSame('#C0392B', $draft['settings']['colors']['primary']);
        $this->assertSame('#FAFAF5', $draft['settings']['colors']['background']);
        $this->assertSame('#FFD166', $draft['settings']['colors']['accent']);
        $this->assertSame('Poppins', $draft['settings']['typography']['heading_font']);
        $this->assertSame('Free shipping over $50', $draft['settings']['header']['announcement_text']);
        $this->assertSame('/pages/shipping', $draft['settings']['header']['announcement_link']);

        [$hero, $featured, $features, $faq, $video] = $draft['sections'];
        $this->assertSame('Summer sale', $hero['settings']['heading']);
        $this->assertSame('Up to 50% off', $hero['settings']['text']);
        $this->assertSame('/collections/summer', $hero['settings']['button_link']);
        $this->assertSame('large', $hero['settings']['height']);
        $this->assertSame(Collection::withoutGlobalScope('store')->where('handle', 'summer')->value('id'), $featured['settings']['collection']);
        $this->assertSame(6, $featured['settings']['limit']);
        $this->assertSame('3', $featured['settings']['columns']);
        $this->assertSame('Safe', $features['settings']['item2_title']);
        $this->assertSame('', $features['settings']['item3_title']);
        $this->assertFalse($faq['enabled']);
        $this->assertSame('30 days.', $faq['settings']['a1']);
        $this->assertStringContainsString('dQw4w9WgXcQ', $video['settings']['video_url']);
    }

    public function test_rejects_unknown_files(): void
    {
        $this->as($this->a)->withHeader('Accept', 'application/json')->post('/api/admin/themes/import', ['file' => $this->zip(['readme.txt' => 'hi'])])
            ->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_new_sections_render_on_the_storefront(): void
    {
        $config = $this->as($this->a)->getJson('/api/admin/theme')->json('config');
        $config['sections'] = [
            ['type' => 'faq', 'settings' => ['heading' => 'Questions', 'q1' => 'Ship to Canada?', 'a1' => 'Yes.']],
            ['type' => 'video', 'settings' => ['video_url' => 'https://youtu.be/dQw4w9WgXcQ']],
            ['type' => 'video', 'settings' => ['video_url' => 'javascript:alert(1)']],
            ['type' => 'testimonials', 'settings' => ['quote1' => 'Love it <script>', 'author1' => 'Ann']],
        ];
        $this->as($this->a)->putJson('/api/admin/theme', $config)->assertOk();
        $this->as($this->a)->postJson('/api/admin/theme/publish')->assertOk();

        $host = Store::find($this->a['store_id'])->handle.'.'.config('lumee.storefront_domain');
        $this->get("http://{$host}/")->assertOk()
            ->assertSee('Ship to Canada?')
            ->assertSee('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', false)
            ->assertDontSee('javascript:alert', false)
            ->assertSee('Love it &lt;script&gt;', false);
    }
}
