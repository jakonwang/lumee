<?php

namespace Tests\Feature;

use App\LeadFinder\LeadAi;
use App\Models\CollectedItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Translation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Products › Content workshop: brand kit, quality score, AI rewrite + versions, image standardizing. */
class ContentWorkshopTest extends TestCase
{
    use RefreshDatabase;

    private array $a;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('lumee.media_disk'));
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => 'a@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop A'])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
        $this->productId = $this->as()->postJson('/api/admin/products', ['title' => 'RING', 'price' => 40, 'inventory' => 5, 'status' => 'active'])->json('data.id');
    }

    private function as(?array $m = null): static
    {
        $m ??= $this->a;
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$m['token'], 'X-Store-Id' => (string) $m['store_id'], 'Accept' => 'application/json']);
    }

    private function kit(array $extra = []): array
    {
        return [
            'positioning' => 'Everyday fine jewelry', 'audience' => 'Women 25–35', 'voice' => 'warm, confident, concise',
            'banned_words' => ['cheapest'], 'title_pattern' => '[Series] [Item] – [Material]', 'title_min' => 20, 'title_max' => 80,
            'ratio' => '3:4', 'background' => '#F7F5F2', 'padding' => 8, 'min_images' => 2,
            'sections' => ['highlights', 'materials', 'care'], 'min_score' => 0, ...$extra,
        ];
    }

    /** A 400×200 white image with a dark product in the middle. */
    private function image(int $productId, int $position = 0): ProductImage
    {
        $im = imagecreatetruecolor(400, 200);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        imagefilledrectangle($im, 150, 50, 250, 150, imagecolorallocate($im, 30, 30, 30));
        ob_start();
        imagepng($im);
        $path = "stores/{$this->a['store_id']}/products/test-{$position}.png";
        Storage::disk(config('lumee.media_disk'))->put($path, ob_get_clean());

        return ProductImage::withoutGlobalScope('store')->forceCreate(['store_id' => $this->a['store_id'], 'product_id' => $productId,
            'disk' => config('lumee.media_disk'), 'path' => $path, 'width' => 400, 'height' => 200, 'size' => 1000, 'position' => $position]);
    }

    public function test_brand_kit_is_saved_and_the_remove_bg_key_never_comes_back(): void
    {
        $this->as()->putJson('/api/admin/content/brand-kit', $this->kit(['removebg_key' => 'rb-secret']))->assertOk()
            ->assertJsonPath('data.ratio', '3:4')->assertJsonPath('data.removebg_connected', true)->assertJsonMissing(['removebg_key' => 'rb-secret']);
        $raw = json_decode(DB::table('stores')->where('id', $this->a['store_id'])->value('settings'), true);
        $this->assertNotSame('rb-secret', $raw['brand_kit']['removebg_key']);   // encrypted at rest
        $this->as()->putJson('/api/admin/content/brand-kit', $this->kit(['title_min' => 50, 'title_max' => 10]))->assertJsonValidationErrors('title_max');
        $this->as()->putJson('/api/admin/content/brand-kit', $this->kit(['background' => 'red']))->assertJsonValidationErrors('background');
    }

    public function test_score_explains_what_to_fix_and_rises_as_content_improves(): void
    {
        $this->as()->putJson('/api/admin/content/brand-kit', $this->kit())->assertOk();
        $this->as()->putJson('/api/admin/settings/languages', ['default' => 'en', 'published' => ['fr']])->assertOk();

        $before = $this->as()->getJson("/api/admin/products/{$this->productId}/content")->assertOk()->json('data');
        $this->assertLessThan(40, $before['score']);
        $messages = collect($before['checks'])->pluck('message')->filter()->implode(' | ');
        $this->assertStringContainsString('标题全是大写', $messages);
        $this->assertStringContainsString('Français', $messages);   // missing translation
        $this->assertSame(['en', 'fr'], collect($before['languages'])->pluck('code')->all());

        $this->as()->putJson("/api/admin/products/{$this->productId}", [
            'title' => 'Aurora Layered Necklace – 18K Gold Plated', 'product_type' => 'Necklace', 'tags' => ['gold', 'layered', 'gift'],
            'description' => '<p>'.str_repeat('Layered chain in 18K gold plating, tarnish-resistant and light to wear. ', 4).'</p><ul><li>Length 40 cm</li></ul>',
            'seo' => ['title' => 'Aurora Necklace', 'description' => 'Layered gold necklace'],
        ])->assertOk();
        $after = $this->as()->getJson("/api/admin/products/{$this->productId}/content")->json('data');
        $this->assertGreaterThan($before['score'] + 30, $after['score']);
        $this->assertSame($after['score'], Product::withoutGlobalScope('store')->find($this->productId)->content_score);

        // The list shows the lowest scores first, with their main problems
        $list = $this->as()->getJson('/api/admin/content/products')->assertOk();
        $this->assertSame($this->productId, $list->json('data.0.id'));
        $this->assertNotEmpty($list->json('data.0.issues'));
        $this->as()->postJson('/api/admin/content/rescore')->assertOk()->assertJsonPath('scored', 1);
    }

    public function test_ai_rewrite_is_a_suggestion_until_accepted_and_versions_can_be_restored(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        $this->as()->putJson('/api/admin/settings/languages', ['default' => 'en', 'published' => ['fr']])->assertOk();
        $this->mock(LeadAi::class, fn ($m) => $m->shouldReceive('json')->andReturn([
            'title' => 'Aurora Ring – Sterling Silver', 'description_html' => '<h3>Highlights</h3><ul><li onclick="x()">Silver</li></ul><script>bad()</script>',
            'seo_title' => 'Aurora Ring', 'seo_description' => 'A sterling silver ring.', 'notes' => ['Add the ring size'],
        ]));

        $s = $this->as()->postJson("/api/admin/products/{$this->productId}/content/suggest", ['locale' => 'en'])->assertOk()->json('data');
        $this->assertSame('RING', $s['current']['title']);
        $this->assertStringNotContainsString('script', $s['suggestion']['description']);
        $this->assertStringNotContainsString('onclick', $s['suggestion']['description']);
        $this->assertSame('RING', Product::withoutGlobalScope('store')->find($this->productId)->title);   // not saved yet

        // Accept (default language → the product itself), then restore the original
        $this->as()->postJson("/api/admin/products/{$this->productId}/content/apply", [
            'locale' => 'en', 'title' => $s['suggestion']['title'], 'description' => $s['suggestion']['description'],
            'seo_title' => 'Aurora Ring', 'seo_description' => 'A sterling silver ring.',
        ])->assertOk();
        $p = Product::withoutGlobalScope('store')->find($this->productId);
        $this->assertSame('Aurora Ring – Sterling Silver', $p->title);
        $this->assertSame('Aurora Ring', $p->seo['title']);
        $versions = $this->as()->getJson("/api/admin/products/{$this->productId}/content")->json('data.versions');
        $this->assertSame('RING', $versions[0]['title']);
        $this->as()->postJson("/api/admin/products/{$this->productId}/content/versions/{$versions[0]['id']}/restore")->assertOk();
        $this->assertSame('RING', Product::withoutGlobalScope('store')->find($this->productId)->title);

        // Another language is saved as its translation
        $this->as()->postJson("/api/admin/products/{$this->productId}/content/apply", ['locale' => 'fr', 'title' => 'Bague Aurora en argent', 'description' => '<p>Argent.</p>'])->assertOk();
        $this->assertSame('Bague Aurora en argent', Translation::withoutGlobalScope('store')->where('resource_id', $this->productId)->where('locale', 'fr')->where('field', 'title')->value('value'));
        $this->as()->postJson("/api/admin/products/{$this->productId}/content/apply", ['locale' => 'ja', 'title' => 'x'])->assertJsonValidationErrors('locale');   // not used anywhere
    }

    public function test_images_are_standardized_to_the_brand_ratio_and_can_be_reverted(): void
    {
        $this->as()->putJson('/api/admin/content/brand-kit', $this->kit(['ratio' => '3:4', 'background' => '#F7F5F2']))->assertOk();
        $img = $this->image($this->productId);

        $this->as()->postJson('/api/admin/content/images/standardize', ['product_ids' => [$this->productId], 'scope' => 'main'])
            ->assertOk()->assertJsonPath('done', 1)->assertJsonPath('errors', []);
        $img->refresh();
        $this->assertSame([1200, 1600], [$img->width, $img->height]);   // 3:4, 1600 tall
        $this->assertStringEndsWith('.webp', $img->path);
        $this->assertNotNull($img->original_path);
        $disk = Storage::disk(config('lumee.media_disk'));
        $this->assertTrue($disk->exists($img->original_path));
        $out = imagecreatefromstring($disk->get($img->path));
        $bg = imagecolorsforindex($out, imagecolorat($out, 5, 5));
        foreach ([[0xF7, 'red'], [0xF5, 'green'], [0xF2, 'blue']] as [$want, $ch]) {
            $this->assertEqualsWithDelta($want, $bg[$ch], 4);   // brand background (WebP is lossy)
        }
        $centre = imagecolorsforindex($out, imagecolorat($out, 600, 800));
        $this->assertLessThan(60, $centre['red']);   // the product (white border trimmed, then centred)

        // Running it again starts from the original; reverting brings the original back
        $this->as()->postJson('/api/admin/content/images/standardize', ['product_ids' => [$this->productId], 'scope' => 'all'])->assertJsonPath('done', 1);
        $this->as()->postJson("/api/admin/products/{$this->productId}/images/{$img->id}/revert")->assertOk()->assertJsonPath('data.processed', false);
        $img->refresh();
        $this->assertSame([400, 200], [$img->width, $img->height]);
        $this->assertNull($img->original_path);
        $this->assertCount(1, $disk->allFiles("stores/{$this->a['store_id']}/products"));   // processed copies cleaned up

        // Background removal needs the merchant's remove.bg key
        $this->as()->postJson('/api/admin/content/images/standardize', ['product_ids' => [$this->productId], 'scope' => 'main', 'remove_background' => true])
            ->assertOk()->assertJsonPath('done', 0)->assertJsonPath('errors.0.product_id', $this->productId);
    }

    public function test_ai_scene_pictures_keep_the_product_and_respect_the_monthly_allowance(): void
    {
        $src = $this->image($this->productId);
        // Not connected yet
        $this->as()->postJson("/api/admin/products/{$this->productId}/images/{$src->id}/scene", ['scene' => 'lifestyle'])->assertJsonValidationErrors('scene');

        $this->as()->putJson('/api/admin/content/brand-kit', $this->kit(['image_ai_provider' => 'openai', 'image_ai_key' => 'sk-img-secret', 'image_ai_quota' => 1]))->assertOk()
            ->assertJsonPath('image_ai.connected', true)->assertJsonPath('image_ai.quota', 1)->assertJsonMissing(['sk-img-secret']);
        $raw = json_decode(DB::table('stores')->where('id', $this->a['store_id'])->value('settings'), true);
        $this->assertNotSame('sk-img-secret', $raw['brand_kit']['image_ai_key']);   // encrypted at rest

        $im = imagecreatetruecolor(1024, 1536);
        ob_start();
        imagepng($im);
        $png = ob_get_clean();
        \Illuminate\Support\Facades\Http::fake(['api.openai.com/*' => \Illuminate\Support\Facades\Http::response(['data' => [['b64_json' => base64_encode($png)]]])]);

        $res = $this->as()->postJson("/api/admin/products/{$this->productId}/images/{$src->id}/scene", ['scene' => 'model', 'note' => 'beach, golden hour'])->assertCreated();
        $this->assertSame('scene', $res->json('data.ai'));
        $this->assertSame(1, $res->json('image_ai.used'));
        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => $r->url() === 'https://api.openai.com/v1/images/edits'
            && $r->hasHeader('Authorization', 'Bearer sk-img-secret')
            && str_contains($r->body(), 'Keep the product from the input image exactly as it is')
            && str_contains($r->body(), 'beach, golden hour') && str_contains($r->body(), 'Everyday fine jewelry'));
        $img = ProductImage::withoutGlobalScope('store')->latest('id')->first();
        $this->assertSame([1024, 1536, 1], [$img->width, $img->height, $img->position]);
        Storage::disk(config('lumee.media_disk'))->assertExists($img->path);
        $this->as()->getJson("/api/admin/products/{$this->productId}/content")->assertJsonPath('data.images.1.ai', 'scene');

        // Allowance used up; unknown scene rejected
        $this->as()->postJson("/api/admin/products/{$this->productId}/images/{$src->id}/scene", ['scene' => 'lifestyle'])->assertJsonValidationErrors('scene');
        $this->as()->postJson("/api/admin/products/{$this->productId}/images/{$src->id}/scene", ['scene' => 'space'])->assertJsonValidationErrors('scene');
        \Illuminate\Support\Facades\Http::assertSentCount(1);
    }

    public function test_collected_products_below_the_minimum_score_stay_drafts(): void
    {
        $this->as()->putJson('/api/admin/content/brand-kit', $this->kit(['min_score' => 90]))->assertOk();
        $item = CollectedItem::withoutGlobalScope('store')->forceCreate([
            'store_id' => $this->a['store_id'], 'url' => 'https://x.example.com/p/1', 'url_hash' => sha1('x'), 'platform' => 'page', 'status' => 'ready',
            'data' => \App\Collect\Item::normalize(['title' => 'Linen Shirt', 'currency' => 'USD', 'variants' => [['options' => [], 'cost' => 1000, 'price' => 2999]]]),
        ]);
        $this->as()->postJson('/api/admin/collect/items/bulk', ['ids' => [$item->id], 'action' => 'publish', 'status' => 'active'])->assertOk();
        $item->refresh();
        $this->assertSame('published', $item->status);
        $this->assertStringContainsString('低于上架标准 90', $item->error);
        $this->assertSame('draft', Product::withoutGlobalScope('store')->find($item->product_id)->status);
    }

    public function test_content_is_per_store(): void
    {
        $b = $this->postJson('/api/admin/auth/register', ['name' => 'B', 'email' => 'b@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop B'])->json();
        $mb = ['token' => $b['token'], 'store_id' => $b['stores'][0]['id']];
        $this->as($mb)->getJson("/api/admin/products/{$this->productId}/content")->assertNotFound();
        $this->as($mb)->postJson('/api/admin/content/images/standardize', ['product_ids' => [$this->productId], 'scope' => 'main'])->assertJsonPath('done', 0);
        $this->as($mb)->getJson('/api/admin/content/products')->assertJsonPath('meta.total', 0);
    }
}
