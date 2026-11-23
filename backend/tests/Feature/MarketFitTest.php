<?php

namespace Tests\Feature;

use App\Collect\Item;
use App\Collect\Pipeline;
use App\Markets\MarketFit;
use App\Markets\MarketRules;
use App\Models\CollectedItem;
use App\Models\MarketProductExclusion;
use App\Models\ProductMarketFit;
use App\Models\ProductOpportunity;
use App\Models\Store;
use App\Services\ImageFetcher;
use App\Tenancy\CurrentStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Market fit: which markets a product suits, publishing where it fits, and each country's storefront order. */
class MarketFitTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private array $a;

    private array $us;

    private array $gb;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.storefront_scheme' => 'http', 'lumee.storefront_port' => null]);
        \Illuminate\Support\Facades\Storage::fake(config('lumee.media_disk'));
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => 'a@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop A'])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
        Http::fake(['open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['USD' => 1, 'GBP' => 0.8, 'EUR' => 0.9]])]);
        $this->artisan('lumee:exchange-rates')->assertSuccessful();
        $this->us = $this->market('United States', 'us', ['US'], 'USD', 'en');
        $this->gb = $this->market('United Kingdom', 'uk', ['GB'], 'GBP', 'en');
    }

    private function as(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->a['token'], 'X-Store-Id' => (string) $this->a['store_id'], 'Accept' => 'application/json']);
    }

    private function market(string $name, string $handle, array $countries, string $currency, string $locale): array
    {
        return $this->as()->postJson('/api/admin/markets', [
            'name' => $name, 'handle' => $handle, 'countries' => $countries, 'currency' => $currency,
            'default_locale' => $locale, 'locales' => [], 'price_adjustment' => 0, 'rounding' => 'none', 'exchange_rate' => null, 'enabled' => true,
        ])->assertCreated()->json('data');
    }

    private function store(): Store
    {
        $store = Store::find($this->a['store_id']);
        app(CurrentStore::class)->set($store);

        return $store;
    }

    private function collected(string $title, string $description = '', int $price = 2500): CollectedItem
    {
        $store = $this->store();
        $data = Item::normalize(['title' => $title, 'description' => $description, 'product_type' => '', 'tags' => [], 'images' => [], 'currency' => 'USD',
            'variants' => [['cost' => 800, 'price' => $price, 'options' => []]]]);

        return app(Pipeline::class)->receive($store, 'https://supplier.example.com/p/'.md5($title), 'shopify', $data);
    }

    public function test_rules_know_plugs_seasons_restricted_goods_and_festivals(): void
    {
        $dec = CarbonImmutable::create(2026, 12, 10);
        $hard = fn (array $hits) => collect($hits)->contains('hard', true);

        $this->assertTrue($hard(MarketRules::check('hair dryer uk plug', ['US'], $dec)));
        $this->assertFalse($hard(MarketRules::check('hair dryer uk plug', ['GB'], $dec)));
        $this->assertGreaterThan(0, array_sum(array_column(MarketRules::check('hair dryer 英规', ['GB'], $dec), 'delta')));

        // December: winter in Germany, summer in Brazil, never winter in Dubai
        $this->assertGreaterThan(0, array_sum(array_column(MarketRules::check('winter down jacket', ['DE'], $dec), 'delta')));
        $this->assertLessThan(0, array_sum(array_column(MarketRules::check('winter down jacket', ['BR'], $dec), 'delta')));
        $this->assertLessThan(0, array_sum(array_column(MarketRules::check('winter down jacket', ['AE'], $dec), 'delta')));
        $this->assertGreaterThan(0, array_sum(array_column(MarketRules::check('bikini set', ['BR'], $dec), 'delta')));

        $this->assertTrue($hard(MarketRules::check('disposable vape pen', ['SG'], $dec)));
        $this->assertTrue($hard(MarketRules::check('craft beer gift box', ['SA'], $dec)));
        $this->assertFalse($hard(MarketRules::check('craft beer gift box', ['US'], $dec)));
        $this->assertGreaterThan(0, array_sum(array_column(MarketRules::check('ramadan lantern decoration', ['AE'], $dec), 'delta')));
        $this->assertSame([], MarketRules::check('silver ring', ['US'], $dec));
    }

    public function test_keyword_matching(): void
    {
        $this->assertSame(1.0, (float) MarketFit::covers('silk scarf', 'pure silk scarves for women'));
        $this->assertSame(0.0, (float) MarketFit::covers('silk scarf', 'silky hair band'));
        $this->assertSame(1.0, (float) MarketFit::covers('真丝围巾', '新款真丝围巾女'));
        $this->assertSame(0.6, MarketFit::covers('large silk square scarf', 'silk square scarf'));
    }

    public function test_collected_items_are_scored_per_market_and_published_where_they_fit(): void
    {
        $store = $this->store();
        ProductOpportunity::create(['market_id' => $this->gb['id'], 'keyword' => 'silk scarf', 'source' => 'manual', 'score' => 90]);

        $scarf = $this->collected('Silk Scarf Floral');
        $fits = $scarf->fresh()->checks['markets'];
        $this->assertSame(90, $fits[$this->gb['id']]['score']);
        $this->assertNull($fits[$this->us['id']]['score']);   // no evidence for the US yet
        $this->assertContains('silk scarf', $fits[$this->gb['id']]['tags']);

        $dryer = $this->collected('Travel Hair Dryer', '<p>Comes with a UK plug.</p>');
        $fits = $dryer->fresh()->checks['markets'];
        $this->assertTrue($fits[$this->us['id']]['hard']);
        $this->assertLessThanOrEqual(15, $fits[$this->us['id']]['score']);
        $this->assertGreaterThan(50, $fits[$this->gb['id']]['score']);

        // The list shows each item's fit
        $row = collect($this->as()->getJson('/api/admin/collect/items')->assertOk()->json('data'))->firstWhere('id', $dryer->id);
        $this->assertCount(2, $row['fits']);

        // Publish "where it fits": the UK-plug dryer is not sold in the US, the scarf everywhere (US unknown stays on)
        $this->app->instance(ImageFetcher::class, new ImageFetcher(fn () => ['93.184.216.34']));
        $this->as()->postJson('/api/admin/collect/items/bulk', ['ids' => [$scarf->id, $dryer->id], 'action' => 'publish', 'status' => 'active', 'market_mode' => 'fit', 'min_fit' => 50])->assertOk();
        $dryerId = $dryer->fresh()->product_id;
        $scarfId = $scarf->fresh()->product_id;
        $this->assertNotNull($dryerId);
        $this->assertTrue(MarketProductExclusion::where('market_id', $this->us['id'])->where('product_id', $dryerId)->exists());
        $this->assertFalse(MarketProductExclusion::where('product_id', $scarfId)->exists());

        // The product keeps its fit
        $this->assertSame(90, ProductMarketFit::where('product_id', $scarfId)->where('market_id', $this->gb['id'])->value('score'));
        $markets = collect($this->as()->getJson("/api/admin/products/{$dryerId}/markets")->assertOk()->json('data'))->keyBy('id');
        $this->assertLessThanOrEqual(15, $markets[$this->us['id']]['fit']['score']);
    }

    public function test_ai_judgement_is_validated_and_weighed_in(): void
    {
        $store = $this->store();
        $markets = MarketFit::markets($store);
        $ai = MarketFit::parseJudgement(['markets' => [
            ['market_id' => $this->us['id'], 'score' => 140, 'reason' => '美国户外露营很流行', 'tags' => ['Camping', 'outdoor']],
            ['market_id' => $this->gb['id'], 'score' => 30, 'reason' => '英国多雨，需求一般', 'tags' => []],
            ['market_id' => 999999, 'score' => 90, 'reason' => 'x', 'tags' => []],   // not one of ours
        ]], $markets);
        $this->assertSame([$this->us['id'], $this->gb['id']], array_keys($ai));
        $this->assertSame(100, $ai[$this->us['id']]['score']);
        $this->assertSame(['camping', 'outdoor'], $ai[$this->us['id']]['tags']);

        $fits = app(MarketFit::class)->evaluate($store, MarketFit::itemFacts(['title' => 'Camping Lantern', 'variants' => [['price' => 3000]]]), $markets, $ai);
        $this->assertSame(100, $fits[$this->us['id']]['score']);
        $this->assertSame(30, $fits[$this->gb['id']]['score']);
        $this->assertStringContainsString('露营', $fits[$this->us['id']]['reasons'][0]);
    }

    public function test_each_country_sees_what_suits_it_first(): void
    {
        // Older product: a strong fit for the UK, a poor one for the US. Newer product: no evidence.
        $teaSet = $this->as()->postJson('/api/admin/products', ['title' => 'Bone China Tea Set', 'price' => 40, 'inventory' => 5, 'status' => 'active'])->json('data.id');
        $mug = $this->as()->postJson('/api/admin/products', ['title' => 'Insulated Travel Mug', 'price' => 20, 'inventory' => 5, 'status' => 'active'])->json('data.id');
        $this->store();
        ProductMarketFit::create(['product_id' => $teaSet, 'market_id' => $this->gb['id'], 'score' => 88]);
        ProductMarketFit::create(['product_id' => $teaSet, 'market_id' => $this->us['id'], 'score' => 20]);
        app(CurrentStore::class)->set(null);

        $order = function (string $path) {
            $html = $this->get(self::HOST.$path)->assertOk()->getContent();

            return strpos($html, 'Bone China Tea Set') < strpos($html, 'Insulated Travel Mug') ? 'tea-first' : 'mug-first';
        };
        $this->assertSame('mug-first', $order('/'));          // default site: newest first
        $this->assertSame('tea-first', $order('/en-uk/'));    // UK: the best fit first
        $this->assertSame('mug-first', $order('/en-us/'));    // US: the poor fit goes last
    }

    public function test_products_are_rescored_on_demand_and_daily(): void
    {
        $id = $this->as()->postJson('/api/admin/products', ['title' => 'Disposable Vape Pen', 'price' => 10, 'inventory' => 5, 'status' => 'active'])->json('data.id');
        $sg = $this->market('Singapore', 'sg', ['SG'], 'USD', 'en');

        $this->as()->postJson('/api/admin/products-market-fit', ['ids' => [$id]])->assertOk()->assertJsonPath('done', 1);
        $this->assertLessThanOrEqual(15, ProductMarketFit::withoutGlobalScope('store')->where('product_id', $id)->where('market_id', $sg['id'])->value('score'));

        // AI needs the store's Claude key
        $this->as()->postJson("/api/admin/products/{$id}/market-fit", ['ai' => true])->assertStatus(422);
        $this->as()->postJson("/api/admin/products/{$id}/market-fit")->assertOk();

        ProductMarketFit::withoutGlobalScope('store')->delete();
        $this->artisan('lumee:market-fit')->assertSuccessful();
        $this->assertSame(3, ProductMarketFit::withoutGlobalScope('store')->where('product_id', $id)->count());
    }

    public function test_fits_are_per_store(): void
    {
        $other = $this->postJson('/api/admin/auth/register', ['name' => 'B', 'email' => 'b@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop B'])->json();
        $id = $this->as()->postJson('/api/admin/products', ['title' => 'Mug', 'price' => 10, 'inventory' => 5, 'status' => 'active'])->json('data.id');
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$other['token'], 'X-Store-Id' => (string) $other['stores'][0]['id'], 'Accept' => 'application/json'])
            ->postJson("/api/admin/products/{$id}/market-fit")->assertNotFound();
        $this->assertSame(0, ProductMarketFit::withoutGlobalScope('store')->where('product_id', $id)->count());
    }
}
