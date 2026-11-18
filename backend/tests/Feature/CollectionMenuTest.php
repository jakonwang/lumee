<?php

namespace Tests\Feature;

use App\Models\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectionMenuTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private array $a;

    /** @var array<string,int> product title => id */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->register('a@example.com', 'Shop A');
        foreach ([['Gold Ring', 120], ['Silver Ring', 60], ['Pearl Necklace', 90]] as [$title, $price]) {
            $this->p[$title] = $this->as($this->a)->postJson('/api/admin/products', [
                'title' => $title, 'price' => $price, 'inventory' => 5, 'status' => 'active',
            ])->json('data.id');
        }
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

    private function rings(): array
    {
        return $this->as($this->a)->postJson('/api/admin/collections', [
            'title' => 'Rings',
            'description' => 'Stackable rings',
            'product_ids' => [$this->p['Silver Ring'], $this->p['Gold Ring']],   // manual order
        ])->assertCreated()->json('data');
    }

    public function test_collection_crud_and_manual_order(): void
    {
        $c = $this->rings();
        $this->assertSame('rings', $c['handle']);
        $this->assertSame(['Silver Ring', 'Gold Ring'], array_column($c['products'], 'title'));

        $this->as($this->a)->getJson('/api/admin/collections')->assertJsonPath('data.0.products_count', 2);

        // "all" is reserved for the all-products page
        $this->as($this->a)->postJson('/api/admin/collections', ['title' => 'All'])->assertJsonPath('data.handle', 'all-1');

        $this->as($this->a)->putJson("/api/admin/collections/{$c['id']}", [
            'product_ids' => [$this->p['Gold Ring']], 'sort_order' => 'price_asc',
        ])->assertOk()->assertJsonCount(1, 'data.products')->assertJsonPath('data.sort_order', 'price_asc');

        $this->as($this->a)->deleteJson("/api/admin/collections/{$c['id']}")->assertNoContent();
        $this->assertDatabaseCount('products', 3);   // products survive
    }

    public function test_storefront_collection_pages_and_sorting(): void
    {
        $c = $this->rings();
        // A draft product in the collection must not be shown
        $this->as($this->a)->putJson("/api/admin/products/{$this->p['Gold Ring']}", ['status' => 'draft']);

        $html = $this->shopper()->get(self::HOST.'/collections/rings')->assertOk()->getContent();
        $this->assertStringContainsString('Stackable rings', $html);
        $this->assertStringContainsString('Silver Ring', $html);
        $this->assertStringNotContainsString('Gold Ring', $html);

        $this->as($this->a)->putJson("/api/admin/products/{$this->p['Gold Ring']}", ['status' => 'active']);

        // Manual order, then explicit price sorts
        $order = fn ($html) => strpos($html, 'Silver Ring') < strpos($html, 'Gold Ring') ? 'silver-first' : 'gold-first';
        $this->assertSame('silver-first', $order($this->shopper()->get(self::HOST.'/collections/rings')->getContent()));
        $this->assertSame('gold-first', $order($this->shopper()->get(self::HOST.'/collections/rings?sort=price_desc')->getContent()));
        $this->assertSame('silver-first', $order($this->shopper()->get(self::HOST.'/collections/rings?sort=price_asc')->getContent()));
        $this->shopper()->get(self::HOST.'/collections/rings?sort=evil')->assertOk();   // unknown sort ignored

        $this->shopper()->get(self::HOST.'/collections/all')->assertOk()->assertSee('Pearl Necklace')->assertSee('3 products');
        $this->shopper()->get(self::HOST.'/collections')->assertOk()->assertSee('Rings');

        // Unpublished collection is hidden
        $this->as($this->a)->putJson("/api/admin/collections/{$c['id']}", ['published' => false]);
        $this->shopper()->get(self::HOST.'/collections/rings')->assertNotFound();
    }

    public function test_product_editor_sets_collections(): void
    {
        $c = $this->rings();
        $necklaces = $this->as($this->a)->postJson('/api/admin/collections', ['title' => 'Necklaces'])->json('data');

        $this->as($this->a)->putJson("/api/admin/products/{$this->p['Pearl Necklace']}", [
            'collection_ids' => [$necklaces['id'], $c['id']],
        ])->assertOk()->assertJsonCount(2, 'data.collection_ids');

        // Added to the end of the existing manual order
        $this->assertSame(['Silver Ring', 'Gold Ring', 'Pearl Necklace'],
            array_column($this->as($this->a)->getJson("/api/admin/collections/{$c['id']}")->json('data.products'), 'title'));

        $this->as($this->a)->putJson("/api/admin/products/{$this->p['Pearl Necklace']}", ['collection_ids' => [$necklaces['id']]])
            ->assertJsonCount(1, 'data.collection_ids');
    }

    public function test_collections_are_store_isolated(): void
    {
        $c = $this->rings();
        $b = $this->register('b@example.com', 'Shop B');
        $bProduct = $this->as($b)->postJson('/api/admin/products', ['title' => 'B item', 'price' => 1, 'status' => 'active'])->json('data.id');

        $this->as($b)->getJson("/api/admin/collections/{$c['id']}")->assertNotFound();
        $this->as($b)->getJson('/api/admin/collections')->assertJsonCount(0, 'data');
        // Can't put another store's product into my collection, or my product into theirs
        $this->as($this->a)->putJson("/api/admin/collections/{$c['id']}", ['product_ids' => [$bProduct]])->assertStatus(422);
        $this->as($b)->putJson("/api/admin/products/{$bProduct}", ['collection_ids' => [$c['id']]])->assertStatus(422);
        $this->shopper()->get('http://shop-b.lumee.localhost/collections/rings')->assertNotFound();
    }

    public function test_menus_default_sanitize_and_render(): void
    {
        $c = $this->rings();

        $res = $this->as($this->a)->getJson('/api/admin/menus')->assertOk();
        $this->assertSame(['main', 'footer'], array_column($res['data'], 'handle'));

        $saved = $this->as($this->a)->putJson('/api/admin/menus/main', ['items' => [
            ['title' => 'Home', 'type' => 'home'],
            ['title' => 'Jewelry', 'type' => 'all_products', 'children' => [
                ['title' => 'Rings', 'type' => 'collection', 'target_id' => $c['id'],
                    'children' => [['title' => 'Too deep', 'type' => 'home']]],          // lifted to level 2
                ['title' => 'Pearl', 'type' => 'product', 'target_id' => $this->p['Pearl Necklace']],
            ]],
            ['title' => 'XSS', 'type' => 'url', 'url' => 'javascript:alert(1)'],           // dropped
            ['title' => 'Proto', 'type' => 'url', 'url' => '//evil.example'],               // dropped
            ['title' => 'Blog', 'type' => 'url', 'url' => 'https://blog.example.com'],
            ['title' => 'Ghost', 'type' => 'collection', 'target_id' => 999999],            // dropped
            ['title' => '', 'type' => 'home'],                                              // dropped
        ]])->assertOk()->json('data.items');

        $this->assertSame(['Home', 'Jewelry', 'Blog'], array_column($saved, 'title'));
        $this->assertSame(['Rings', 'Too deep', 'Pearl'], array_column($saved[1]['children'], 'title'));
        $this->assertSame([], $saved[1]['children'][0]['children']);
        $this->assertSame('Rings', $saved[1]['children'][0]['target_label']);

        $html = $this->shopper()->get(self::HOST.'/')->assertOk()->getContent();
        $this->assertStringContainsString('href="/collections/rings"', $html);
        $this->assertStringContainsString('href="/products/pearl-necklace"', $html);
        $this->assertStringContainsString('href="https://blog.example.com"', $html);
        $this->assertStringNotContainsString('javascript:', $html);

        // Deleting the collection: link disappears from the storefront, editor flags it as broken
        $this->as($this->a)->deleteJson("/api/admin/collections/{$c['id']}");
        $this->assertStringNotContainsString('href="/collections/rings"', $this->shopper()->get(self::HOST.'/')->getContent());
        $this->as($this->a)->getJson('/api/admin/menus')->assertJsonPath('data.0.items.1.children.0.broken', true);

        // Another store can't link to my product
        $b = $this->register('b@example.com', 'Shop B');
        $this->as($b)->putJson('/api/admin/menus/main', ['items' => [
            ['title' => 'Steal', 'type' => 'product', 'target_id' => $this->p['Pearl Necklace']],
        ]])->assertJsonCount(0, 'data.items');
        $this->as($b)->putJson('/api/admin/menus/sidebar', ['items' => []])->assertNotFound();
    }

    public function test_theme_sections_use_collections(): void
    {
        $c = $this->rings();
        $b = $this->register('b@example.com', 'Shop B');
        $bCollection = $this->as($b)->postJson('/api/admin/collections', ['title' => 'B Only'])->json('data.id');

        $cfg = $this->as($this->a)->getJson('/api/admin/theme')->json('config');
        $cfg['sections'][1]['settings']['collection'] = $c['id'];               // featured products from Rings
        $cfg['sections'][] = ['type' => 'collection_list', 'id' => 'cl', 'settings' => ['collection_1' => $c['id'], 'collection_2' => $bCollection]];
        $saved = $this->as($this->a)->putJson('/api/admin/theme', $cfg)->json('config');
        $this->assertNull($saved['sections'][3]['settings']['collection_2']);   // other store's collection rejected
        $this->as($this->a)->postJson('/api/admin/theme/publish');

        $html = $this->shopper()->get(self::HOST.'/')->assertOk()->getContent();
        $featured = substr($html, strpos($html, 'data-section-id="featured_products-2"'), 4000);
        $this->assertStringContainsString('Silver Ring', $featured);
        $this->assertStringNotContainsString('Pearl Necklace', $featured);       // not in Rings
        $this->assertStringContainsString('href="/collections/rings"', $featured); // "View all" goes to the collection
        $this->assertStringContainsString('data-section-id="cl"', $html);
    }
}
