<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PagesSeoSearchTest extends TestCase
{
    use RefreshDatabase;

    private const A = 'http://shop-a.lumee.localhost';

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->merchant('a@example.com', 'Shop A');
        $this->b = $this->merchant('b@example.com', 'Shop B');
    }

    private function merchant(string $email, string $store): array
    {
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => $email, 'password' => 'secret-pass', 'store_name' => $store])->assertCreated();

        return ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
    }

    private function as(?array $m = null): static
    {
        $m ??= $this->a;
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$m['token'], 'X-Store-Id' => (string) $m['store_id'], 'Accept' => 'application/json']);
    }

    /** Storefront base URL as the app builds it (dev config may add a port). */
    private function base(): string
    {
        return \App\Models\Store::find($this->a['store_id'])->load('domains')->storefrontUrl();
    }

    private function front(): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this;
    }

    public function test_pages_markdown_is_rendered_safely_and_unpublished_pages_404(): void
    {
        $id = $this->as()->postJson('/api/admin/pages', [
            'title' => 'About Us',
            'body' => "## Our story\n\nHand-made **jewelry**.\n\n<script>alert(1)</script>\n\n[bad](javascript:alert(1)) [good](https://example.com)",
            'seo' => ['title' => '  About   Shop A ', 'description' => 'We make rings.'],
        ])->assertCreated()->assertJsonPath('data.handle', 'about-us')->assertJsonPath('data.seo.title', 'About Shop A')->json('data.id');

        $html = $this->front()->get(self::A.'/pages/about-us')->assertOk()
            ->assertSee('<h2>Our story</h2>', false)
            ->assertSee('<strong>jewelry</strong>', false)
            ->assertSee('<title>About Shop A</title>', false)
            ->assertSee('<meta name="description" content="We make rings.">', false)
            ->assertSee('<link rel="canonical" href="'.$this->base().'/pages/about-us">', false)
            ->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('javascript:alert', $html);
        $this->assertStringContainsString('href="https://example.com"', $html);

        $this->as()->putJson("/api/admin/pages/{$id}", ['published' => false])->assertOk();
        $this->front()->get(self::A.'/pages/about-us')->assertNotFound();

        // Another store can't read or edit it
        $this->as($this->b)->getJson("/api/admin/pages/{$id}")->assertNotFound();
        $this->as($this->b)->putJson("/api/admin/pages/{$id}", ['title' => 'x'])->assertNotFound();
    }

    public function test_page_templates_and_menu_links(): void
    {
        $res = $this->as()->getJson('/api/admin/pages')->assertOk();
        $this->assertContains('refund-policy', array_column($res->json('templates'), 'handle'));

        $page = $this->as()->postJson('/api/admin/pages', ['template' => 'refund-policy'])->assertCreated()
            ->assertJsonPath('data.published', false)->assertJsonPath('data.title', 'Refund Policy')->json('data');
        $this->assertNotContains('refund-policy', array_column($this->as()->getJson('/api/admin/pages')->json('templates'), 'handle'));
        $this->as()->putJson("/api/admin/pages/{$page['id']}", ['published' => true])->assertOk();

        // Footer menu → page link; another store's page id is dropped
        $other = $this->as($this->b)->postJson('/api/admin/pages', ['title' => 'Secret'])->json('data.id');
        $this->as()->putJson('/api/admin/menus/footer', ['items' => [
            ['title' => 'Refunds', 'type' => 'page', 'target_id' => $page['id']],
            ['title' => 'Nope', 'type' => 'page', 'target_id' => $other],
            ['title' => 'Search', 'type' => 'search'],
        ]])->assertOk()->assertJsonCount(2, 'data.items');
        $this->front()->get(self::A.'/')->assertSee('href="/pages/refund-policy"', false)->assertSee('href="/search"', false);

        $this->as()->postJson('/api/admin/pages/preview', ['body' => '# Hi <img src=x onerror=alert(1)>'])
            ->assertOk()->assertJsonPath('html', "<h1>Hi </h1>\n");
    }

    public function test_product_and_collection_seo_structured_data_and_home(): void
    {
        $product = $this->as()->postJson('/api/admin/products', [
            'title' => 'Rose Quartz Ring', 'description' => '<p>A soft pink ring.</p>', 'vendor' => 'Bloom',
            'price' => 40, 'inventory' => 5, 'status' => 'active', 'sku' => 'RQ-1',
            'seo' => ['title' => 'Rose Quartz Ring | Handmade', 'description' => 'Handmade rose quartz ring in sterling silver.'],
        ])->assertCreated()->assertJsonPath('data.seo.title', 'Rose Quartz Ring | Handmade');

        $html = $this->front()->get(self::A.'/products/rose-quartz-ring')->assertOk()
            ->assertSee('<title>Rose Quartz Ring | Handmade</title>', false)
            ->assertSee('<meta property="og:type" content="product">', false)
            ->getContent();
        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $ld = json_decode($m[1], true);
        $this->assertSame(['Product', 'Rose Quartz Ring', 'Bloom', '40.00', 'USD', 'https://schema.org/InStock', 'A soft pink ring.'], [
            $ld['@type'], $ld['name'], $ld['brand']['name'], $ld['offers'][0]['price'], $ld['offers'][0]['priceCurrency'], $ld['offers'][0]['availability'], $ld['description'],
        ]);

        $this->as()->postJson('/api/admin/collections', ['title' => 'Rings', 'seo' => ['title' => 'Shop rings', 'description' => null]])
            ->assertCreated()->assertJsonPath('data.seo.title', 'Shop rings');
        $this->front()->get(self::A.'/collections/rings')->assertSee('<title>Shop rings</title>', false);

        $this->as()->putJson('/api/admin/settings/store', [
            'name' => 'Shop A', 'currency' => 'USD', 'timezone' => 'UTC', 'seo' => ['title' => 'Shop A — Fine jewelry', 'description' => 'Rings and more.'],
        ])->assertOk()->assertJsonPath('store.seo.title', 'Shop A — Fine jewelry');
        $this->front()->get(self::A.'/')->assertSee('<title>Shop A — Fine jewelry</title>', false)->assertSee('content="Rings and more."', false);
    }

    public function test_search_finds_active_products_only_and_escapes_wildcards(): void
    {
        $this->as()->postJson('/api/admin/products', ['title' => 'Amethyst Necklace', 'price' => 50, 'status' => 'active', 'tags' => ['purple']]);
        $this->as()->postJson('/api/admin/products', ['title' => 'Hidden Amethyst', 'price' => 50, 'status' => 'draft']);
        $this->as()->postJson('/api/admin/products', ['title' => 'Gold Hoops', 'price' => 30, 'status' => 'active', 'sku' => 'GH-100']);
        $this->as($this->b)->postJson('/api/admin/products', ['title' => 'Amethyst Ring (shop B)', 'price' => 10, 'status' => 'active']);

        $this->front()->get(self::A.'/search?q=amethyst')->assertOk()
            ->assertSee('1 result for “amethyst”', false)->assertSee('Amethyst Necklace')
            ->assertDontSee('Hidden Amethyst')->assertDontSee('shop B')
            ->assertSee('<meta name="robots" content="noindex">', false);
        $this->front()->get(self::A.'/search?q=purple')->assertSee('Amethyst Necklace');
        $this->front()->get(self::A.'/search?q=GH-100')->assertSee('Gold Hoops');
        $this->front()->get(self::A.'/search?q=%25')->assertSee('No products found');     // "%" is literal, not "match all"
        $this->front()->get(self::A.'/search')->assertOk()->assertSee('Search our store');
    }

    public function test_sitemap_and_robots(): void
    {
        $this->as()->postJson('/api/admin/products', ['title' => 'Live Ring', 'price' => 10, 'status' => 'active']);
        $this->as()->postJson('/api/admin/products', ['title' => 'Draft Ring', 'price' => 10, 'status' => 'draft']);
        $this->as()->postJson('/api/admin/pages', ['title' => 'FAQ', 'body' => 'Q&A']);

        $this->front()->get(self::A.'/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee('<loc>'.$this->base().'/products/live-ring</loc>', false)
            ->assertSee('<loc>'.$this->base().'/pages/faq</loc>', false)
            ->assertDontSee('draft-ring');

        $this->front()->get(self::A.'/robots.txt')->assertOk()
            ->assertSee('Disallow: /checkout')->assertSee('Sitemap: '.$this->base().'/sitemap.xml');
        $this->front()->get('http://localhost/robots.txt')->assertOk()->assertSee('User-agent: *');
    }
}
