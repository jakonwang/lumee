<?php

namespace Tests\Feature;

use App\Imports\ProductCsv;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\ImageFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImportTest extends TestCase
{
    use RefreshDatabase;

    private array $a;

    private const HEADER = 'Handle,Title,Body (HTML),Vendor,Type,Tags,Status,Collection,Option1 Name,Option1 Value,Option2 Name,Option2 Value,Variant SKU,Variant Price,Variant Compare At Price,Variant Inventory Qty,Image Src';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $res = $this->postJson('/api/admin/auth/register', [
            'name' => 'M', 'email' => 'a@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop A',
        ])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
        // Public-looking DNS for image hosts in tests (no real network)
        $this->app->instance(ImageFetcher::class, new ImageFetcher(fn (string $host) => match ($host) {
            'internal.example' => ['10.0.0.5'],
            default => ['93.184.216.34'],
        }));
    }

    private function as(?array $m = null): static
    {
        $m ??= $this->a;
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$m['token'], 'X-Store-Id' => (string) $m['store_id'], 'Accept' => 'application/json']);
    }

    private function upload(string $csv, string $mode = 'create', string $name = 'products.csv')
    {
        return $this->as()->post('/api/admin/products/import', [
            'file' => UploadedFile::fake()->createWithContent($name, $csv),
            'mode' => $mode,
        ]);
    }

    private function importNow(string $csv, string $mode = 'create'): array
    {
        $id = $this->upload($csv, $mode)->assertCreated()->json('data.id');
        // QUEUE_CONNECTION=sync in tests → the job runs during this request
        return $this->as()->postJson("/api/admin/products/import/{$id}/start")->assertOk()->json('data') + ['id' => $id];
    }

    private function csv(array $rows): string
    {
        return self::HEADER."\n".implode("\n", $rows)."\n";
    }

    private function png(): string
    {
        $im = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($im);

        return ob_get_clean();
    }

    public function test_preview_counts_and_row_errors(): void
    {
        $csv = $this->csv([
            'linen-shirt,Linen Shirt,"<p>Soft <b>linen</b>.</p><p>Breathable.</p>",Bloom,Shirts,"summer, linen",active,Summer,Color,White,Size,M,LS-W-M,49.00,59.00,20,',
            'linen-shirt,,,,,,,,,White,,L,LS-W-L,49.00,,15,',
            'linen-shirt,,,,,,,,,Blue,,M,LS-B-M,"1,049.50",,8,',
            'linen-shirt,,,,,,,,,Blue,,M,DUP,49,,1,',            // duplicate combination
            'linen-shirt,,,,,,,,,Green,,,,49,,1,',                 // missing Size value
            'tote,Canvas Tote,,,,,,,,,,,,abc,,5,',                 // invalid price → product has no variants
            'cap,Cap,,,,,draft,,,,,,,15,,3,',
        ]);

        $res = $this->upload($csv)->assertCreated();
        $res->assertJsonPath('data.status', 'preview')
            ->assertJsonPath('data.summary.products', 2)           // linen-shirt + cap (tote has no valid variant)
            ->assertJsonPath('data.summary.variants', 4)
            ->assertJsonPath('data.summary.new', 2);
        $messages = collect($res->json('data.summary.errors'))->groupBy('row')->map(fn ($g) => $g->pluck('message')->implode(' | '));
        $this->assertStringContainsString('规格组合重复', $messages[5]);
        $this->assertStringContainsString('缺少规格「Size」', $messages[6]);
        $this->assertStringContainsString('价格「abc」', $messages[7]);
        $this->assertDatabaseCount('products', 0);                 // preview never touches the catalog
    }

    public function test_import_creates_products_with_variants_collections_and_text_description(): void
    {
        $result = $this->importNow($this->csv([
            'linen-shirt,Linen Shirt,"<p>Soft <b>linen</b>.</p><p>Breathable.</p>",Bloom,Shirts,"summer, linen",active,"Summer, Tops",Color,White,Size,M,LS-W-M,49.00,59.00,20,',
            'linen-shirt,,,,,,,,,White,,L,LS-W-L,49.00,,15,',
            'linen-shirt,,,,,,,,,Blue,,M,LS-B-M,"1,049.50",,8,',
            'cap,Cap,,,,,draft,,Title,Default Title,,,CAP-1,15,,3,',
        ]));

        $this->assertSame('done', $result['status']);
        $this->assertSame(2, $result['results']['created']);

        $shirt = Product::withoutGlobalScope('store')->where('handle', 'linen-shirt')->with('variants', 'options', 'collections')->first();
        $this->assertSame("Soft linen.\nBreathable.", $shirt->description);
        $this->assertSame(['summer', 'linen'], $shirt->tags);
        $this->assertSame(['Color', 'Size'], $shirt->options->pluck('name')->all());
        $this->assertSame(['White / M', 'White / L', 'Blue / M'], $shirt->variants->pluck('title')->all());
        $this->assertSame(104950, $shirt->variants[2]->price);
        $this->assertSame(['Summer', 'Tops'], $shirt->collections->pluck('title')->sort()->values()->all());

        $cap = Product::withoutGlobalScope('store')->where('handle', 'cap')->with('variants', 'options')->first();
        $this->assertSame('draft', $cap->status);
        $this->assertCount(0, $cap->options);                    // "Title / Default Title" = no options
        $this->assertSame('Default', $cap->variants[0]->title);
    }

    public function test_create_mode_skips_and_overwrite_updates_keeping_variant_ids(): void
    {
        $this->importNow($this->csv([
            'tee,Tee,,,,,active,,Size,S,,,,10,,5,',
            'tee,,,,,,,,,M,,,,10,,5,',
        ]));
        $original = ProductVariant::withoutGlobalScope('store')->orderBy('id')->pluck('id', 'title');

        $updated = $this->csv([
            'tee,Tee v2,,,,,active,,Size,S,,,,12,,9,',
            'tee,,,,,,,,,L,,,,12,,4,',
        ]);
        $skip = $this->importNow($updated, 'create');
        $this->assertSame(1, $skip['results']['skipped']);
        $this->assertSame('Tee', Product::withoutGlobalScope('store')->value('title'));

        $over = $this->importNow($updated, 'overwrite');
        $this->assertSame(1, $over['results']['updated']);
        $tee = Product::withoutGlobalScope('store')->with('variants')->first();
        $this->assertSame('Tee v2', $tee->title);
        $this->assertSame(['S', 'L'], $tee->variants->pluck('title')->all());
        $this->assertSame($original['S'], $tee->variants[0]->id);   // same option → same variant id
        $this->assertSame(1200, $tee->variants[0]->price);
        $this->assertDatabaseCount('products', 1);
    }

    public function test_images_are_downloaded_and_internal_urls_refused(): void
    {
        Http::fake([
            'cdn.example.com/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
            'cdn.example.com/missing.png' => Http::response('', 404),
            '*' => Http::response('nope', 500),
        ]);

        $result = $this->importNow($this->csv([
            'mug,Mug,,,,,active,,,,,,,9,,5,https://cdn.example.com/mug-1.png',
            'mug,,,,,,,,,,,,,,,,https://cdn.example.com/mug-2.png',
            'mug,,,,,,,,,,,,,,,,http://internal.example/secret.png',       // resolves to 10.0.0.5
            'mug,,,,,,,,,,,,,,,,http://169.254.169.254/latest/meta-data',   // cloud metadata IP
        ]));

        $this->assertSame(1, $result['results']['created']);
        $this->assertSame(2, $result['results']['images_failed']);
        $this->assertStringContainsString('内网', $result['results']['errors'][0]['message']);
        $mug = Product::withoutGlobalScope('store')->with('images')->first();
        $this->assertCount(2, $mug->images);
        Storage::disk('public')->assertExists($mug->images[0]->path);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'internal.example') || str_contains($req->url(), '169.254'));
    }

    public function test_gbk_file_with_chinese_headers(): void
    {
        $csv = "标题,价格,库存,描述\n真丝围巾,199,12,手工制作\n";
        $this->upload(mb_convert_encoding($csv, 'GB18030', 'UTF-8'))->assertCreated()
            ->assertJsonPath('data.summary.products', 1)
            ->assertJsonPath('data.summary.sample.0.title', '真丝围巾')
            ->assertJsonPath('data.summary.sample.0.price', 199);
        $this->assertMatchesRegularExpression('/^product-[0-9a-f]{8}$/', $this->as()->getJson('/api/admin/products/import/'.\App\Models\ProductImport::withoutGlobalScope('store')->value('id'))->json('data.summary.sample.0.handle'));
    }

    public function test_export_round_trips(): void
    {
        $this->importNow($this->csv([
            'linen-shirt,Linen Shirt,Plain text,Bloom,Shirts,summer,active,Summer,Color,White,Size,M,LS-W-M,49.00,59.00,20,',
            'linen-shirt,,,,,,,,,Blue,,L,LS-B-L,51.00,,3,',
        ]));

        $res = $this->as()->get('/api/admin/products/export')->assertOk();
        $exported = $res->streamedContent();
        $this->assertStringStartsWith("\u{FEFF}Handle,Title", $exported);

        $parsed = app(ProductCsv::class)->parse($exported);
        $this->assertSame([], $parsed['errors']);
        $p = $parsed['products'][0];
        $this->assertSame('Linen Shirt', $p['title']);
        $this->assertSame(['Summer'], $p['collections']);
        $this->assertSame([['options' => ['Color' => 'White', 'Size' => 'M'], 'price' => '49.00', 'compare_at_price' => '59.00', 'sku' => 'LS-W-M', 'inventory' => 20, 'weight' => 0],
            ['options' => ['Color' => 'Blue', 'Size' => 'L'], 'price' => '51.00', 'compare_at_price' => null, 'sku' => 'LS-B-L', 'inventory' => 3, 'weight' => 0]], $p['variants']);
    }

    public function test_file_type_permissions_and_isolation(): void
    {
        $this->upload("not,a,csv\n", 'create', 'evil.php')->assertStatus(422);
        $this->upload("foo,bar\n1,2\n")->assertStatus(422)->assertJsonPath('message', '没有可导入的商品');

        $id = $this->upload($this->csv(['cap,Cap,,,,,,,,,,,,15,,3,']))->json('data.id');

        // Another store can't see or start my import
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'B', 'email' => 'b@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop B']);
        $b = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
        $this->as($b)->getJson("/api/admin/products/import/{$id}")->assertNotFound();
        $this->as($b)->postJson("/api/admin/products/import/{$id}/start")->assertNotFound();

        // Staff without the products permission can't import or export
        \App\Models\Store::find($this->a['store_id'])->users()->attach(
            \App\Models\User::where('email', 'b@example.com')->value('id'), ['role' => 'staff', 'permissions' => json_encode(['orders'])]);
        $asStaffOfA = ['token' => $b['token'], 'store_id' => $this->a['store_id']];
        $this->as($asStaffOfA)->get('/api/admin/products/export')->assertForbidden();
        $this->as($asStaffOfA)->postJson("/api/admin/products/import/{$id}/start")->assertForbidden();
    }
}
