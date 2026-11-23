<?php

namespace Tests\Feature;

use App\Media\Img;
use App\Models\ProductImage;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Responsive image sizes, the anonymous page cache, and collection filters. */
class StorefrontPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['lumee.storefront_scheme' => 'http', 'lumee.storefront_port' => null]);
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => 'a@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop A'])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
    }

    private function as(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->a['token'], 'X-Store-Id' => (string) $this->a['store_id'], 'Accept' => 'application/json']);
    }

    private function shopper(): static
    {
        $this->flushHeaders();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->app['auth']->forgetGuards();

        return $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0) Chrome/130');
    }

    private function ring(string $title, array $variants, ?string $type = null, ?int $image = null): array
    {
        return $this->as()->postJson('/api/admin/products', [
            'title' => $title, 'status' => 'active', 'product_type' => $type,
            'images' => $image ? [['id' => $image]] : [],
            'options' => collect($variants)->flatMap(fn ($v) => array_keys($v[0]))->unique()->values()
                ->map(fn ($n) => ['name' => $n, 'values' => collect($variants)->pluck("0.$n")->unique()->values()->all()])->all(),
            'variants' => array_map(fn ($v) => ['options' => $v[0], 'price' => $v[1], 'inventory' => $v[2]], $variants),
        ])->assertCreated()->json('data');
    }

    public function test_uploads_are_served_in_storefront_sizes(): void
    {
        $img = $this->as()->post('/api/admin/uploads/images', ['file' => UploadedFile::fake()->image('big.jpg', 2400, 3000)])->assertCreated()->json('data');
        $this->ring('Gold ring', [[['Size' => '6'], 50, 3]], null, $img['id']);
        $path = Img::path($img['url']);
        $this->assertNotNull($path);

        // Product cards ask for a few widths; the browser picks one
        $html = $this->shopper()->get(self::HOST.'/collections/all')->assertOk()->getContent();
        $this->assertStringContainsString('srcset="/_img/320/'.$path.' 320w, /_img/480/'.$path.' 480w', $html);
        $this->assertStringContainsString('src="/_img/480/'.$path.'"', $html);

        // Made once as WebP at that width, then kept on the disk; cached by browsers for a year
        $res = $this->get('/_img/480/'.$path)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->assertStringContainsString('immutable', $res->headers->get('Cache-Control'));
        $this->assertSame(480, imagesx(imagecreatefromstring($res->getContent())));
        $this->assertSame([], $res->headers->getCookies());
        Storage::disk('public')->assertExists(Img::variantPath(480, $path));
        $this->get('/_img/480/'.$path)->assertOk();

        // Only the listed widths, only uploads
        $this->get('/_img/500/'.$path)->assertNotFound();
        $this->get('/_img/480/stores/../.env')->assertNotFound();
        $this->get('/_img/480/stores/1/products/missing.jpg')->assertNotFound();
        $this->assertSame('https://cdn.example.com/a.jpg', Img::url('https://cdn.example.com/a.jpg', 480));
        // Another site's image with a look-alike path is left alone; this app's own hosts are recognised
        $this->assertNull(Img::path('https://other-shop.example/storage/'.$path));
        $this->assertSame($path, Img::path('http://localhost/storage/'.$path));

        // Deleting the image removes its sizes too
        ProductImage::withoutGlobalScope('store')->find($img['id'])->delete();
        Storage::disk('public')->assertMissing(Img::variantPath(480, $path));
    }

    public function test_anonymous_pages_are_cached_and_cleared_on_changes(): void
    {
        config(['lumee.page_cache.enabled' => true]);
        $p = $this->ring('Moon ring', [[['Size' => '6'], 50, 3]]);

        $this->shopper()->get(self::HOST.'/products/moon-ring')->assertOk()->assertHeader('X-Page-Cache', 'MISS');
        $res = $this->shopper()->get(self::HOST.'/products/moon-ring')->assertOk()->assertHeader('X-Page-Cache', 'HIT')->assertSee('Moon ring');
        $this->assertStringNotContainsString('__LUMEE_CSRF_TOKEN__', $res->getContent());   // their own token is put back
        $this->assertSame(2, (int) DB::table('analytics_sessions')->sum('viewed_product'));   // a cached page still counts the view

        // Saving the product shows the change right away
        $this->as()->putJson("/api/admin/products/{$p['id']}", ['title' => 'Moon ring II'])->assertOk();
        $this->shopper()->get(self::HOST.'/products/moon-ring')->assertHeader('X-Page-Cache', 'MISS')->assertSee('Moon ring II');

        // The add-to-cart form works from a cached page (CSRF token belongs to this visitor's session)
        $this->shopper()->get(self::HOST.'/products/moon-ring')->assertHeader('X-Page-Cache', 'HIT');
        $html = $this->get(self::HOST.'/products/moon-ring')->getContent();
        preg_match('/name="_token" value="([^"]+)"/', $html, $m);
        $this->assertSame(session()->token(), $m[1] ?? null);

        // Not cached: shoppers with a bag, previews, other pages
        $variant = $p['variants'][0]['id'];
        $cart = $this->shopper()->withoutMiddleware(ValidateCsrfToken::class)->post(self::HOST.'/cart/add', ['variant_id' => $variant])->getCookie('lumee_cart', false)->getValue();
        $this->shopper()->withUnencryptedCookie('lumee_cart', $cart)->get(self::HOST.'/products/moon-ring')->assertHeaderMissing('X-Page-Cache');
        $this->shopper()->get(self::HOST.'/cart')->assertHeaderMissing('X-Page-Cache');
        $this->shopper()->get(self::HOST.'/products/moon-ring?_preview=x')->assertHeaderMissing('X-Page-Cache');

        // Each language and site has its own copy
        $this->shopper()->get(self::HOST.'/')->assertHeader('X-Page-Cache', 'MISS');
        $this->shopper()->get(self::HOST.'/?utm=1')->assertHeader('X-Page-Cache', 'MISS');
        $this->shopper()->get(self::HOST.'/')->assertHeader('X-Page-Cache', 'HIT');
    }

    public function test_product_ab_test_pages_are_not_cached(): void
    {
        config(['lumee.page_cache.enabled' => true]);
        $p = $this->ring('Star ring', [[['Size' => '6'], 50, 3]]);
        DB::table('content_experiments')->insert(['store_id' => $this->a['store_id'], 'product_id' => $p['id'], 'kind' => 'title',
            'b_value' => 'Star ring B', 'status' => 'running', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->shopper()->get(self::HOST.'/products/star-ring')->assertOk()->assertHeaderMissing('X-Page-Cache');
        $this->shopper()->get(self::HOST.'/products/star-ring')->assertHeaderMissing('X-Page-Cache');
    }

    public function test_collection_filters(): void
    {
        $this->ring('Moon ring', [[['Size' => '6', 'Color' => 'Gold'], 40, 3], [['Size' => '7', 'Color' => 'Gold'], 40, 0]], 'Rings');
        $this->ring('Sun ring', [[['Size' => '7', 'Color' => 'Silver'], 90, 2]], 'Rings');
        $this->ring('Leaf necklace', [[['Color' => 'Gold'], 120, 0]], 'Necklaces');
        $page = fn (string $q = '') => $this->shopper()->get(self::HOST.'/collections/all'.$q)->assertOk();

        // Options, product type, price and stock are offered with counts
        $html = $page()->assertSee('In stock only')->assertSee('Size')->assertSee('Color')->assertSee('Product type')->getContent();
        $this->assertMatchesRegularExpression('/value="Gold"[^>]*>\s*<span>Gold<\/span><em>2<\/em>/', $html);

        // Size 7 + Gold: only a Moon ring variant has both
        $page('?f[o][Size][]=7&f[o][Color][]=Gold')->assertSee('Moon ring')->assertDontSee('Sun ring')->assertDontSee('Leaf necklace')
            ->assertSee('1 product');
        // …and in stock only drops it (that variant is sold out)
        $page('?f[o][Size][]=7&f[o][Color][]=Gold&f[stock]=1')->assertSee('No products match these filters.');
        // Values within a group are "or"
        $page('?f[o][Color][]=Gold&f[o][Color][]=Silver')->assertSee('3 products');
        // Price range (shopper's currency) and type
        $page('?f[min]=50&f[max]=100')->assertSee('Sun ring')->assertDontSee('Moon ring')->assertDontSee('Leaf necklace');
        $page('?f[type][]=Necklaces')->assertSee('Leaf necklace')->assertDontSee('Sun ring');

        // Chips remove one choice; sort keeps the filters
        $html = $page('?f[o][Color][]=Gold&sort=price_desc')->getContent();
        $this->assertTrue(strpos($html, 'Leaf necklace') < strpos($html, 'Moon ring'));
        $this->assertStringContainsString('Clear all', $html);
        $this->assertStringContainsString('name="f[o][Color][]" value="Gold"', $html);

        // Merchants can turn filters off on the collection template
        $d = $this->as()->getJson('/api/admin/theme')->json();
        $c = $d['config'];
        foreach ($c['templates']['collection'] as $i => $s) {
            if ($s['type'] === 'collection_grid') {
                $c['templates']['collection'][$i]['settings']['show_filters'] = false;
            }
        }
        $this->as()->putJson('/api/admin/theme', [...$c, 'id' => $d['id']])->assertOk();
        $this->as()->postJson('/api/admin/theme/publish', ['id' => $d['id']])->assertOk();
        $page()->assertDontSee('In stock only');
        $this->assertNotEmpty(Str::of($html)->length());
    }

    public function test_recently_viewed_block(): void
    {
        $this->ring('Moon ring', [[['Size' => '6'], 50, 3]]);
        $this->ring('Sun ring', [[['Size' => '6'], 70, 0]]);
        // New themes put it on product pages; the page records the visit in the browser and the block fills itself in
        $html = $this->shopper()->get(self::HOST.'/products/moon-ring')->assertOk()->getContent();
        $this->assertStringContainsString('localStorage.setItem(k', $html);
        $this->assertStringContainsString('class="wrap recent-viewed"', $html);
        $this->assertStringContainsString('data-current="moon-ring"', $html);
        // The lookup it uses: in this site's prices, with a card-sized image
        $this->getJson(self::HOST.'/wishlist/products?handles=sun-ring,moon-ring,nope')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.sold_out', true);
    }

    public function test_search_results_can_be_filtered(): void
    {
        $this->ring('Moon ring', [[['Color' => 'Gold'], 40, 3]]);
        $this->ring('Sun ring', [[['Color' => 'Silver'], 90, 2]]);
        $this->ring('Leaf necklace', [[['Color' => 'Gold'], 120, 1]]);
        $this->shopper()->get(self::HOST.'/search?q=ring')->assertOk()->assertSee('2 results')->assertSee('In stock only')
            ->assertSee('name="q" value="ring"', false);
        $this->shopper()->get(self::HOST.'/search?q=ring&f[o][Color][]=Silver')->assertOk()->assertSee('Sun ring')->assertDontSee('Moon ring')->assertSee('Clear all');
        $this->shopper()->get(self::HOST.'/search?q=ring&f[min]=200')->assertOk()->assertSee('No products match these filters.');
    }

    public function test_storefront_is_installable(): void
    {
        $html = $this->shopper()->get(self::HOST.'/')->assertOk()->getContent();
        $this->assertStringContainsString('<link rel="manifest" href="/manifest.webmanifest">', $html);
        $this->assertStringContainsString("serviceWorker.register('/sw.js')", $html);

        $m = $this->get(self::HOST.'/manifest.webmanifest')->assertOk()->assertHeader('Content-Type', 'application/manifest+json')->json();
        $this->assertSame(['Shop A', 'standalone', '/'], [$m['name'], $m['display'], $m['scope']]);
        $icon = $this->get(parse_url(self::HOST, PHP_URL_SCHEME).'://'.parse_url(self::HOST, PHP_URL_HOST).strtok($m['icons'][1]['src'], '?'))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame(512, imagesx(imagecreatefromstring($icon->getContent())));
        $this->get(self::HOST.'/pwa-icon/77.png')->assertNotFound();
        $this->get(self::HOST.'/sw.js')->assertOk()->assertSee("caches.match('/offline')", false);
        $this->get(self::HOST.'/offline')->assertOk()->assertSee('Shop A')->assertSee('Try again');
    }

    public function test_big_sitemaps_are_split_into_pages(): void
    {
        foreach (['A ring', 'B ring', 'C ring'] as $t) {
            $this->ring($t, [[['Size' => '6'], 50, 3]]);
        }
        $all = $this->get(self::HOST.'/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('<urlset', $all);
        $count = substr_count($all, '<url>');

        config(['lumee.sitemap_page_size' => 2]);
        Cache::flush();
        $index = $this->get(self::HOST.'/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('<sitemapindex', $index);
        $this->assertSame((int) ceil($count / 2), substr_count($index, '<sitemap>'));
        $this->assertStringContainsString(self::HOST.'/sitemap.xml?page=1', $index);
        $this->assertSame(2, substr_count($this->get(self::HOST.'/sitemap.xml?page=1')->assertOk()->getContent(), '<url>'));
        $this->get(self::HOST.'/sitemap.xml?page=99')->assertNotFound();
    }
}
