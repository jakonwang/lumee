<?php

namespace Tests\Feature;

use App\Collect\CollectAi;
use App\LeadFinder\SafeFetcher;
use App\Models\CollectedItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ImageFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Products › Collect: collect → clean → price → review → publish. */
class CollectTest extends TestCase
{
    use RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Storage::fake(config('lumee.media_disk'));   // downloaded images never touch real storage
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => 'a@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop A'])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
        // Public-looking DNS for every host in tests (no real network)
        $this->app->instance(SafeFetcher::class, new SafeFetcher(fn () => ['93.184.216.34']));
        $this->app->instance(ImageFetcher::class, new ImageFetcher(fn () => ['93.184.216.34']));
        DB::table('exchange_rates')->insert([
            ['currency' => 'CNY', 'per_usd' => 7.2, 'updated_at' => now()],
            ['currency' => 'EUR', 'per_usd' => 0.9, 'updated_at' => now()],
        ]);
        Http::fake(fn (Request $r) => $this->web($r));
    }

    private function as(?array $m = null): static
    {
        $m ??= $this->a;
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$m['token'], 'X-Store-Id' => (string) $m['store_id'], 'Accept' => 'application/json']);
    }

    /** The fake internet. */
    private function web(Request $r)
    {
        $url = $r->url();
        $json = fn ($d) => Http::response(json_encode($d), 200, ['Content-Type' => 'application/json']);

        return match (true) {
            str_contains($url, 'shop.example.com/cart.js') => $json(['currency' => 'USD']),
            str_contains($url, 'shop.example.com/products/silk-scarf.json') => $json(['product' => $this->shopifyProduct()]),
            str_contains($url, 'other.example.com/products/scarf.json') => $json(['product' => $this->shopifyProduct()]),
            str_contains($url, 'shop.example.com/products.json') && str_contains($url, 'page=1') => $json(['products' => [
                $this->shopifyProduct(), [...$this->shopifyProduct(), 'id' => 2, 'handle' => 'linen-tote', 'title' => 'Linen Tote Bag', 'tags' => ['bags']],
            ]]),
            str_contains($url, 'shop.example.com/products.json') => $json(['products' => []]),
            str_contains($url, 'supplier.example.com/item/1') => Http::response($this->jsonLdPage(), 200, ['Content-Type' => 'text/html; charset=utf-8']),
            str_contains($url, 'blocked.example.com') => Http::response('no', 403, ['Content-Type' => 'text/html']),
            str_contains($url, 'cdn.example.com') => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
            default => Http::response('not found', 404, ['Content-Type' => 'text/html']),
        };
    }

    private function shopifyProduct(): array
    {
        return [
            'id' => 1, 'handle' => 'silk-scarf', 'title' => '2026 NEW Hot Sale SILK SCARF | Free Shipping', 'vendor' => 'Supplier Co', 'product_type' => 'Scarves',
            'body_html' => '<p onclick="x()">Pure mulberry silk.</p><script>track()</script><a href="https://shop.example.com">Shop</a>', 'tags' => 'silk, scarf',
            'options' => [['name' => 'Color', 'values' => ['Rose', 'Sage']]],
            'images' => [['id' => 11, 'src' => 'https://cdn.example.com/scarf-rose.png'], ['id' => 12, 'src' => 'https://cdn.example.com/scarf-sage.png'], ['id' => 13, 'src' => 'https://cdn.example.com/icon-badge.svg']],
            'variants' => [
                ['option1' => 'Rose', 'price' => '24.00', 'compare_at_price' => '40.00', 'sku' => 'SS-R', 'grams' => 80, 'image_id' => 11],
                ['option1' => 'Sage', 'price' => '32.00', 'compare_at_price' => null, 'sku' => 'SS-S', 'grams' => 80, 'image_id' => 12],
            ],
        ];
    }

    private function jsonLdPage(): string
    {
        $ld = json_encode(['@context' => 'https://schema.org', '@type' => 'Product', 'name' => '【爆款】Nike style 925 Silver Ring 925银戒指',
            'description' => 'Sterling silver ring.', 'image' => ['https://cdn.example.com/ring.png'],
            'offers' => ['@type' => 'Offer', 'price' => '28.00', 'priceCurrency' => 'CNY']], JSON_UNESCAPED_UNICODE);

        return "<html><head><script type=\"application/ld+json\">{$ld}</script></head><body>ring</body></html>";
    }

    private function png(): string
    {
        $im = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($im);

        return ob_get_clean();
    }

    private function collect(string $urls, string $mode = 'products', string $currency = 'USD')
    {
        // QUEUE_CONNECTION=sync in tests → the job runs during this request
        return $this->as()->postJson('/api/admin/collect/items', ['urls' => $urls, 'mode' => $mode, 'currency' => $currency]);
    }

    private function item(): CollectedItem
    {
        return CollectedItem::withoutGlobalScope('store')->latest('id')->firstOrFail();
    }

    public function test_shopify_product_is_collected_cleaned_priced_and_published(): void
    {
        $this->collect("https://shop.example.com/collections/all/products/silk-scarf?variant=1\nnot a url")->assertStatus(202)->assertJsonPath('queued', 1);

        $row = $this->as()->getJson('/api/admin/collect/items?status=ready')->assertOk()->json('data.0');
        $this->assertSame('shopify', $row['platform']);
        $this->assertSame('Silk Scarf', $row['title']);           // noise removed, SHOUTING fixed
        $this->assertSame(2, $row['variants_count']);
        $this->assertSame('24.00', $row['price_min']);            // no rule yet: the source price
        $d = $this->item()->data;
        $this->assertStringNotContainsString('<script', $d['description']);
        $this->assertStringNotContainsString('onclick', $d['description']);
        $this->assertStringNotContainsString('<a', $d['description']);
        $this->assertCount(2, $d['images']);                       // the svg badge is dropped
        $this->assertArrayNotHasKey('vendor', $d);                 // the supplier's brand is not ours

        // Pricing rule: ×2.5, crossed-out price ×1.5, ".99" ending
        $this->as()->putJson('/api/admin/collect/pricing-rules', ['rules' => [
            ['name' => 'Default', 'scope' => 'all', 'multiplier' => 2.5, 'add_amount' => 0, 'compare_multiplier' => 1.5, 'rounding' => '0.99'],
        ]])->assertOk()->assertJsonPath('data.0.name', 'Default');
        $id = $this->item()->id;
        $this->as()->postJson('/api/admin/collect/items/bulk', ['ids' => [$id], 'action' => 'reprice'])->assertOk()->assertJsonPath('done', 1);
        $v = $this->as()->getJson("/api/admin/collect/items/{$id}")->json('data.data.variants.0');
        $this->assertSame('60.99', $v['price']);
        $this->assertSame('91.99', $v['compare_at_price']);

        // Review edit, then publish (images downloaded, cost and source recorded)
        $this->as()->putJson("/api/admin/collect/items/{$id}", [
            'title' => 'Mulberry Silk Scarf', 'description' => '<p>Pure mulberry silk.</p>', 'product_type' => 'Scarves', 'tags' => ['silk'],
            'images' => $d['images'],
            'variants' => [['price' => '59.00', 'compare_at_price' => null, 'cost' => '24.00', 'sku' => 'SS-R', 'inventory' => 10], ['price' => '69.00', 'cost' => '32.00']],
        ])->assertOk()->assertJsonPath('data.title', 'Mulberry Silk Scarf');
        $this->as()->postJson('/api/admin/collect/items/bulk', ['ids' => [$id], 'action' => 'publish', 'status' => 'active', 'inventory' => 5])
            ->assertOk()->assertJsonPath('done', 1);

        $product = Product::withoutGlobalScope('store')->where('title', 'Mulberry Silk Scarf')->firstOrFail();
        $this->assertSame('active', $product->status);
        $variants = ProductVariant::withoutGlobalScope('store')->where('product_id', $product->id)->orderBy('position')->get();
        $this->assertSame([5900, 6900], $variants->pluck('price')->all());
        $this->assertSame([2400, 3200], $variants->pluck('cost')->all());
        $this->assertSame([10, 5], $variants->pluck('inventory_quantity')->all());   // edited stock, else the default
        $this->assertNotNull($variants[1]->image_id);                               // variant images kept
        $this->assertSame(2, DB::table('product_images')->where('product_id', $product->id)->count());
        $this->assertDatabaseHas('product_sources', ['product_id' => $product->id, 'platform' => 'shopify']);
        $this->assertSame('published', $this->item()->status);

        // Collecting the same page again is skipped; a near-identical title elsewhere is flagged
        $this->collect('https://shop.example.com/products/silk-scarf')->assertJsonPath('skipped', 1)->assertJsonPath('queued', 0);
    }

    public function test_whole_shopify_store_and_duplicates(): void
    {
        $this->collect('https://shop.example.com', 'store')->assertStatus(202);
        $this->assertSame(2, CollectedItem::withoutGlobalScope('store')->where('status', 'ready')->count());
        $this->assertSame(0, CollectedItem::withoutGlobalScope('store')->where('status', 'pending')->count());   // the placeholder is gone

        $first = CollectedItem::withoutGlobalScope('store')->where('url', 'like', '%silk-scarf')->first();
        $this->as()->postJson('/api/admin/collect/items/bulk', ['ids' => [$first->id], 'action' => 'publish'])->assertOk();
        $this->assertSame('draft', Product::withoutGlobalScope('store')->firstOrFail()->status);   // draft unless asked

        // Another page with the same product → flagged as a duplicate of the catalog product
        $this->collect('https://other.example.com/products/scarf');
        $this->assertSame('similar_title', $this->item()->checks['duplicate']['reason']);
    }

    public function test_page_with_structured_data_in_another_currency(): void
    {
        $this->as()->putJson('/api/admin/collect/pricing-rules', ['rules' => [
            ['name' => 'Rings', 'scope' => 'product_type', 'scope_value' => 'Rings', 'multiplier' => 5, 'rounding' => 'none'],
            ['name' => 'Everything', 'scope' => 'all', 'multiplier' => 3, 'add_amount' => 0, 'rounding' => '0.99'],
        ]])->assertOk();
        $this->collect("https://supplier.example.com/item/1\nhttps://blocked.example.com/p/2", 'products', 'USD');

        $item = CollectedItem::withoutGlobalScope('store')->where('url', 'like', '%supplier%')->first();
        $this->assertSame('ready', $item->status);
        $this->assertSame('CNY', $item->data['currency']);           // the page says so
        $this->assertSame('Everything', $item->data['pricing']['rule']);
        // ¥28 → $3.89 cost → ×3 = 11.67 → 11.99
        $this->assertSame(1199, $item->data['variants'][0]['price']);
        $types = collect($item->checks['notes'])->pluck('type')->all();
        $this->assertContains('translate', $types);
        $this->assertContains('brand', $types);
        $this->assertStringNotContainsString('爆款', $item->data['title']);

        $blocked = CollectedItem::withoutGlobalScope('store')->where('url', 'like', '%blocked%')->first();
        $this->assertSame('failed', $blocked->status);
        $this->assertStringContainsString('访问防护', $blocked->error);

        // Reject and restore
        $this->as()->postJson('/api/admin/collect/items/bulk', ['ids' => [$item->id], 'action' => 'reject'])->assertJsonPath('done', 1);
        $this->as()->getJson('/api/admin/collect/items?status=rejected')->assertJsonPath('meta.total', 1);
        $this->as()->postJson('/api/admin/collect/items/bulk', ['ids' => [$item->id], 'action' => 'restore'])->assertJsonPath('done', 1);
    }

    public function test_browser_extension_sends_pages_with_its_own_key(): void
    {
        $key = $this->as()->postJson('/api/admin/collect/extension-key')->assertOk()->json('key');
        $this->assertMatchesRegularExpression('/^lmc_\d+_[A-Za-z0-9]{40}$/', $key);
        $html = '<meta property="og:title" content="Pearl Drop Earrings"><meta property="og:image" content="/img/pearl.png">'
            .'<meta property="product:price:amount" content="45,90"><meta property="product:price:currency" content="EUR">';

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->postJson('/api/collector/pages', ['url' => 'https://boutique.example.com/p/pearl', 'html' => $html], ['X-Collector-Key' => $key])
            ->assertCreated()->assertJsonPath('title', 'Pearl Drop Earrings');
        $item = $this->item();
        $this->assertSame('extension', $item->platform);
        $this->assertSame('https://boutique.example.com/img/pearl.png', $item->data['images'][0]);
        $this->assertSame(4590, $item->data['variants'][0]['cost']);   // "45,90" read as a decimal comma
        $this->assertSame((int) $this->a['store_id'], $item->store_id);

        // Nothing on the page → what the extension picked out of it
        $this->postJson('/api/collector/pages', ['url' => 'https://x.example.com/1', 'html' => '<p>hi</p>', 'extracted' => ['title' => 'Linen Shirt', 'price' => '$19.90', 'images' => ['https://x.example.com/a.jpg']]], ['X-Collector-Key' => $key])
            ->assertCreated()->assertJsonPath('title', 'Linen Shirt');

        // A wrong or replaced key is refused
        $this->postJson('/api/collector/pages', ['url' => 'https://x.example.com/2', 'html' => $html], ['X-Collector-Key' => $key.'x'])->assertUnauthorized();
        $this->as()->postJson('/api/admin/collect/extension-key')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->postJson('/api/collector/pages', ['url' => 'https://x.example.com/3', 'html' => $html], ['X-Collector-Key' => $key])->assertUnauthorized();
    }

    public function test_ai_answer_translates_options_without_breaking_variants(): void
    {
        $d = \App\Collect\Item::normalize([
            'title' => '丝巾', 'currency' => 'CNY', 'options' => [['name' => '颜色', 'values' => ['粉色', '绿色']]],
            'variants' => [['options' => ['颜色' => '粉色'], 'cost' => 1000, 'price' => 999], ['options' => ['颜色' => '绿色'], 'cost' => 1200, 'price' => 1299]],
        ]);
        $out = app(CollectAi::class)->apply($d, [
            'title' => 'Silk Scarf', 'description_html' => '<p>Silk.</p>', 'product_type' => 'Scarf', 'tags' => ['silk'],
            'options' => [['name' => 'Color', 'values' => ['Pink', 'Green']]], 'risks' => ['Check the silk content'],
        ]);
        $this->assertSame([['name' => 'Color', 'values' => ['Pink', 'Green']]], $out['options']);
        $this->assertSame(['Color' => 'Green'], $out['variants'][1]['options']);
        $this->assertSame(1299, $out['variants'][1]['price']);
        $this->assertSame(['Check the silk content'], $out['ai_risks']);

        // Without a Claude key the AI action explains what to set up
        $this->collect('https://shop.example.com/products/silk-scarf');
        $this->as()->postJson('/api/admin/collect/items/bulk', ['ids' => [$this->item()->id], 'action' => 'ai'])->assertJsonValidationErrors('action');
    }

    public function test_items_are_per_store(): void
    {
        $this->collect('https://shop.example.com/products/silk-scarf');
        $id = $this->item()->id;
        $b = $this->postJson('/api/admin/auth/register', ['name' => 'B', 'email' => 'b@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop B'])->json();
        $mb = ['token' => $b['token'], 'store_id' => $b['stores'][0]['id']];
        $this->as($mb)->getJson("/api/admin/collect/items/{$id}")->assertNotFound();
        $this->as($mb)->postJson('/api/admin/collect/items/bulk', ['ids' => [$id], 'action' => 'delete'])->assertJsonPath('done', 0);
        $this->as($mb)->getJson('/api/admin/collect/items?status=ready')->assertJsonPath('meta.total', 0);
    }
}
