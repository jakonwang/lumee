<?php

namespace Tests\Feature;

use App\Models\ContentExperiment;
use App\Models\Market;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Products › Test-and-learn: per-market product stats, verdicts, search terms, A/B tests. */
class TestAndLearnTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private array $a;

    private int $storeId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.storefront_scheme' => 'http', 'lumee.storefront_port' => null]);
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => 'a@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop A'])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
        $this->storeId = (int) $res['stores'][0]['id'];
        Http::fake(['open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['USD' => 1, 'MXN' => 20]])]);
        $this->artisan('lumee:exchange-rates')->assertSuccessful();
    }

    private function as(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->a['token'], 'X-Store-Id' => (string) $this->a['store_id'], 'Accept' => 'application/json']);
    }

    private function front(?string $visitor = null): static
    {
        $this->flushHeaders();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->app['auth']->forgetGuards();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0) Chrome/130');

        return $visitor ? $this->withCookie('lm_v', $visitor) : $this;
    }

    private function product(string $title, int $daysOld = 0): int
    {
        $id = $this->as()->postJson('/api/admin/products', ['title' => $title, 'price' => 40, 'inventory' => 50, 'status' => 'active'])->json('data.id');
        Product::withoutGlobalScope('store')->whereKey($id)->update(['created_at' => now()->subDays($daysOld)]);

        return $id;
    }

    private function stats(int $productId, int $views, int $carts, int $marketId = 0): void
    {
        DB::table('product_market_stats')->insert(['store_id' => $this->storeId, 'product_id' => $productId, 'market_id' => $marketId,
            'date' => now()->subDay()->toDateString(), 'views' => $views, 'carts' => $carts]);
    }

    public function test_views_carts_and_searches_are_counted_per_market(): void
    {
        $id = $this->product('Rose Ring');
        $mx = $this->as()->postJson('/api/admin/markets', ['name' => 'Mexico', 'handle' => 'mx', 'countries' => ['MX'], 'currency' => 'MXN',
            'default_locale' => 'es', 'locales' => [], 'price_adjustment' => 0, 'rounding' => 'none', 'enabled' => true])->json('data.id');

        $this->front()->get(self::HOST.'/products/rose-ring')->assertOk();
        $this->front()->get(self::HOST.'/es-mx/products/rose-ring')->assertOk();
        $this->front()->get(self::HOST.'/es-mx/products/rose-ring')->assertOk();
        $variant = ProductVariant::withoutGlobalScope('store')->where('product_id', $id)->value('id');
        $this->front()->post(self::HOST.'/es-mx/cart/add', ['variant_id' => $variant, 'quantity' => 1]);
        // Bots are never counted
        $this->front()->withHeader('User-Agent', 'Googlebot/2.1')->get(self::HOST.'/products/rose-ring')->assertOk();

        $rows = DB::table('product_market_stats')->where('product_id', $id)->get()->keyBy('market_id');
        $this->assertSame(1, (int) $rows[0]->views);
        $this->assertSame([2, 1], [(int) $rows[$mx]->views, (int) $rows[$mx]->carts]);

        $this->front()->get(self::HOST.'/search?q=Rose')->assertOk();
        $this->front()->get(self::HOST.'/search?q=unicorn%20lamp')->assertOk();
        $this->front()->get(self::HOST.'/es-mx/search?q=Unicorn  Lamp')->assertOk();
        $terms = $this->as()->getJson('/api/admin/testing/search-terms')->assertOk();
        $this->assertSame(3, $terms->json('total'));
        $this->assertSame(2, $terms->json('zero'));
        $this->assertSame('unicorn lamp', $terms->json('no_results.0.term'));
        $this->assertSame(2, $terms->json('no_results.0.zero_results'));
        $this->assertEqualsCanonicalizing(['默认站点', 'Mexico'], $terms->json('no_results.0.markets'));
        $this->assertSame(1, $this->as()->getJson("/api/admin/testing/search-terms?market={$mx}")->json('total'));

        $m = collect($this->as()->getJson("/api/admin/testing/products/{$id}")->assertOk()->json('data.markets'))->keyBy('id');
        $this->assertSame(2, $m[$mx]['views']);
        $this->assertSame(1, $m[0]['views']);
    }

    public function test_products_get_a_verdict_against_the_store_average(): void
    {
        $this->as()->putJson('/api/admin/testing/settings', ['window_days' => 14, 'min_views' => 50, 'hot_multiplier' => 2])->assertOk();
        $hot = $this->product('Hot Scarf', 2);
        $cold = $this->product('Cold Vase', 15);
        $quiet = $this->product('Quiet Lamp', 5);
        $testing = $this->product('New Mug', 1);
        $old = $this->product('Classic Tee', 60);   // not new: only in the "all products" view
        $this->stats($hot, 100, 30);    // 30% add-to-cart
        $this->stats($cold, 200, 2);    // 1%
        $this->stats($quiet, 2, 0);
        $this->stats($testing, 20, 2);
        $this->stats($old, 678, 66);    // keeps the store average near 10%

        $r = $this->as()->getJson('/api/admin/testing')->assertOk();
        $status = collect($r->json('data'))->pluck('status', 'id');
        $this->assertSame(['hot', 'cold', 'no_traffic', 'testing'], [$status[$hot], $status[$cold], $status[$quiet], $status[$testing]]);
        $this->assertFalse($status->has($old));
        $this->assertEqualsWithDelta(10.0, $r->json('baseline'), 0.1);
        $this->assertNotEmpty(collect($r->json('data'))->firstWhere('id', $cold)['advice']);
        $this->assertSame(1, $r->json('counts.hot'));
        $this->assertCount(1, $this->as()->getJson('/api/admin/testing?status=cold')->json('data'));
        $this->assertTrue(collect($this->as()->getJson('/api/admin/testing?scope=all')->json('data'))->pluck('id')->contains($old));

        // Bulk: retire the slow one, feature the winner
        $col = $this->as()->postJson('/api/admin/collections', ['title' => 'Best sellers'])->json('data.id');
        $this->as()->postJson('/api/admin/testing/bulk', ['ids' => [$cold], 'action' => 'draft'])->assertJsonPath('done', 1);
        $this->as()->postJson('/api/admin/testing/bulk', ['ids' => [$hot], 'action' => 'collection', 'collection_id' => $col])->assertJsonPath('done', 1);
        $this->assertSame('draft', Product::withoutGlobalScope('store')->find($cold)->status);
        $this->assertTrue(DB::table('collection_product')->where('collection_id', $col)->where('product_id', $hot)->exists());

        // The daily job stores each verdict on the product
        $this->artisan('lumee:test-and-learn')->assertSuccessful();
        $this->assertSame('hot', Product::withoutGlobalScope('store')->find($hot)->test_status);
    }

    public function test_title_ab_test_shows_each_visitor_one_version_and_applies_the_winner(): void
    {
        $id = $this->product('Rose Ring');
        $this->as()->postJson("/api/admin/products/{$id}/experiments", ['kind' => 'title', 'b_value' => 'Rose Quartz Promise Ring'])->assertCreated();
        $this->as()->postJson("/api/admin/products/{$id}/experiments", ['kind' => 'title', 'b_value' => 'Another'])->assertJsonValidationErrors('kind');   // one at a time
        $e = ContentExperiment::withoutGlobalScope('store')->firstOrFail();

        // Find a visitor in each arm (the arm is a stable hash of visitor + test)
        $visitors = ['a' => null, 'b' => null];
        while (! $visitors['a'] || ! $visitors['b']) {
            $v = (string) Str::uuid();
            $visitors[crc32($v.'|'.$e->id) % 2 === 0 ? 'a' : 'b'] ??= $v;
        }
        $this->front($visitors['b'])->get(self::HOST.'/products/rose-ring')->assertSee('Rose Quartz Promise Ring');
        $this->front($visitors['b'])->get(self::HOST.'/products/rose-ring')->assertSee('Rose Quartz Promise Ring');   // same version every time
        $this->front($visitors['a'])->get(self::HOST.'/products/rose-ring')->assertDontSee('Rose Quartz Promise Ring');
        $variant = ProductVariant::withoutGlobalScope('store')->where('product_id', $id)->value('id');
        $this->front($visitors['b'])->post(self::HOST.'/cart/add', ['variant_id' => $variant, 'quantity' => 1]);
        $e->refresh();
        $this->assertSame([1, 0, 2, 1], [$e->a_views, $e->a_carts, $e->b_views, $e->b_carts]);
        $this->assertSame('Rose Ring', Product::withoutGlobalScope('store')->find($id)->title);   // nothing saved

        // Enough data: B clearly better
        DB::table('content_experiments')->where('id', $e->id)->update(['a_views' => 400, 'a_carts' => 20, 'b_views' => 400, 'b_carts' => 44]);
        $exp = $this->as()->getJson("/api/admin/testing/products/{$id}")->json('data.experiments.0');
        $this->assertTrue($exp['stats']['ready']);
        $this->assertSame('b', $exp['stats']['leader']);
        $this->assertLessThan(0.05, $exp['stats']['p_value']);
        $this->assertSame(120.0, (float) $exp['stats']['uplift']);

        $this->as()->postJson("/api/admin/experiments/{$e->id}/finish", ['winner' => 'b'])->assertOk()->assertJsonPath('data.0.status', 'finished');
        $this->assertSame('Rose Quartz Promise Ring', Product::withoutGlobalScope('store')->find($id)->title);
        $this->assertDatabaseHas('product_content_versions', ['product_id' => $id, 'title' => 'Rose Ring', 'reason' => 'before_test']);
        $this->as()->postJson("/api/admin/experiments/{$e->id}/finish", ['winner' => 'a'])->assertStatus(422);
    }

    public function test_image_ab_test_promotes_the_winning_image(): void
    {
        $id = $this->product('Rose Ring');
        $imgs = collect([0, 1])->map(fn ($p) => ProductImage::withoutGlobalScope('store')->forceCreate(['store_id' => $this->storeId, 'product_id' => $id,
            'disk' => 'public', 'path' => "x/{$p}.jpg", 'width' => 900, 'height' => 1200, 'size' => 1, 'position' => $p]));
        $this->as()->postJson("/api/admin/products/{$id}/experiments", ['kind' => 'image', 'b_value' => (string) $imgs[0]->id])->assertJsonValidationErrors('b_value');   // already the main image
        $this->as()->postJson("/api/admin/products/{$id}/experiments", ['kind' => 'image', 'b_value' => (string) $imgs[1]->id])->assertCreated();
        $e = ContentExperiment::withoutGlobalScope('store')->firstOrFail();

        $this->as()->postJson("/api/admin/experiments/{$e->id}/finish", ['winner' => 'b'])->assertOk();
        $this->assertSame(0, ProductImage::withoutGlobalScope('store')->find($imgs[1]->id)->position);
        $this->assertSame(1, ProductImage::withoutGlobalScope('store')->find($imgs[0]->id)->position);
    }

    public function test_testing_data_is_per_store(): void
    {
        $id = $this->product('Rose Ring');
        $b = $this->postJson('/api/admin/auth/register', ['name' => 'B', 'email' => 'b@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop B'])->json();
        $this->app['auth']->forgetGuards();
        $hb = ['Authorization' => 'Bearer '.$b['token'], 'X-Store-Id' => (string) $b['stores'][0]['id'], 'Accept' => 'application/json'];
        $this->withHeaders($hb)->getJson("/api/admin/testing/products/{$id}")->assertNotFound();
        $this->withHeaders($hb)->postJson("/api/admin/products/{$id}/experiments", ['kind' => 'title', 'b_value' => 'x'])->assertNotFound();
        $this->withHeaders($hb)->postJson('/api/admin/testing/bulk', ['ids' => [$id], 'action' => 'archive'])->assertJsonPath('done', 0);
        $this->assertSame('active', Product::withoutGlobalScope('store')->find($id)->status);
        $this->assertSame([], $this->withHeaders($hb)->getJson('/api/admin/testing')->json('data'));
        $this->assertSame(0, Market::withoutGlobalScope('store')->count());
    }
}
