<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ProductReview;
use App\Models\ProductVariant;
use App\Models\ShippingRate;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Phase B: new blocks, product / collection / cart pages from blocks, campaign landing pages, style settings. */
class ThemeBlocksTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.payments.test_gateway' => true, 'lumee.storefront_scheme' => 'http', 'lumee.storefront_port' => null]);
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => 'a@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop A'])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
    }

    private function as(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->a['token'], 'X-Store-Id' => (string) $this->a['store_id'], 'Accept' => 'application/json']);
    }

    private function front(): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        return $this;
    }

    private function product(array $attrs): int
    {
        return $this->as()->postJson('/api/admin/products', ['price' => 50, 'inventory' => 20, 'status' => 'active', ...$attrs])->assertCreated()->json('data.id');
    }

    private function editor(): array
    {
        return $this->as()->getJson('/api/admin/theme')->assertOk()->json();
    }

    private function saveAndPublish(array $d, array $config): array
    {
        $r = $this->as()->putJson('/api/admin/theme', [...$config, 'id' => $d['id']])->assertOk()->json();
        $this->as()->postJson('/api/admin/theme/publish', ['id' => $d['id']])->assertOk();

        return $r;
    }

    private function block(array $d, string $type, array $settings = [], string $id = ''): array
    {
        $def = $d['schema']['sections'][$type];
        $values = array_map(fn ($f) => $f['default'] ?? null, $def['fields']);

        return ['id' => $id ?: $type.'-t', 'type' => $type, 'enabled' => true, 'settings' => [...$values, ...$settings]];
    }

    public function test_product_page_is_built_from_blocks_and_keeps_its_product_info(): void
    {
        $this->product(['title' => 'Rose Ring']);
        $d = $this->editor();
        $this->assertSame(['main_product', 'product_reviews', 'frequently_bought', 'related_products'], array_column($d['config']['templates']['product'], 'type'));
        $this->assertSame(['home', 'product', 'collection', 'cart'], array_column($d['places'], 'key'));
        $this->assertStringContainsString('/products/rose-ring?_preview=', $d['places'][1]['preview_url']);

        // Shipping estimate and an FAQ after the product info; the product info can't be removed or hidden
        $c = $d['config'];
        $c['templates']['product'] = [
            $this->block($d, 'faq', ['heading' => 'Ring questions', 'q1' => 'Is it real silver?', 'a1' => 'Yes, 925.'], 'faq-p'),
            $this->block($d, 'shipping_eta', ['processing' => 1, 'transit_min' => 3, 'transit_max' => 5, 'note' => 'Tracked shipping'], 'eta-p'),
            $this->block($d, 'hero', [], 'hero-p'),   // not allowed on product pages → dropped
        ];
        $r = $this->saveAndPublish($d, $c);
        $types = array_column($r['config']['templates']['product'], 'type');
        $this->assertSame(['main_product', 'faq', 'shipping_eta'], $types);   // product info put back first

        $html = $this->front()->get(self::HOST.'/products/rose-ring')->assertOk()
            ->assertSee('Rose Ring')->assertSee('Ring questions')->assertSee('Tracked shipping')->assertSee('Order today — arrives between', false)->getContent();
        $this->assertLessThan(strpos($html, 'Ring questions'), strpos($html, 'class="wrap pdp"'));

        // Hiding the product info is ignored
        $c = $r['config'];
        $c['templates']['product'][0]['enabled'] = false;
        $this->assertTrue($this->saveAndPublish($d, $c)['config']['templates']['product'][0]['enabled']);
    }

    public function test_new_home_blocks_render_with_their_own_data(): void
    {
        $sale = $this->product(['title' => 'Sale Scarf', 'price' => 30, 'compare_at_price' => 45]);
        $this->product(['title' => 'Plain Tee']);
        $this->as()->postJson('/api/admin/discounts', ['code' => 'HOLA10', 'type' => 'percentage', 'value' => 10, 'active' => true])->assertCreated();
        $this->as()->postJson('/api/admin/discounts', ['code' => 'OFF5', 'type' => 'percentage', 'value' => 5, 'active' => false])->assertCreated();
        ProductReview::withoutGlobalScope('store')->forceCreate(['store_id' => $this->a['store_id'], 'product_id' => $sale, 'author' => 'Ana Lopez', 'rating' => 5,
            'body' => 'Love it', 'status' => 'published', 'images' => [['url' => 'https://img.example.com/ugc-1.jpg']]]);

        $d = $this->editor();
        $c = $d['config'];
        $c['sections'] = [
            $this->block($d, 'slideshow', ['slide1_heading' => 'Summer drop', 'slide1_link' => '/collections/all'], 'ss'),
            $this->block($d, 'flash_sale', ['heading' => 'Lightning deals', 'ends_at' => now()->addDay()->format('Y-m-d\TH:i')], 'fs'),
            $this->block($d, 'coupon_bar', ['code1' => 'HOLA10', 'code2' => 'OFF5', 'code3' => 'NOPE'], 'cb'),
            $this->block($d, 'product_tabs', ['tab1_label' => 'Fresh', 'tab1_source' => 'newest', 'tab2_label' => 'Deals', 'tab2_source' => 'sale', 'tab3_label' => ''], 'pt'),
            $this->block($d, 'marquee', ['text' => "Free returns\nFast shipping"], 'mq'),
            $this->block($d, 'trust_bar', ['returns' => '30-day returns'], 'tb'),
            $this->block($d, 'ugc_wall', [], 'ugc'),
            $this->block($d, 'category_circles', ['visibility' => 'mobile'], 'cc'),
        ];
        $this->saveAndPublish($d, $c);

        $home = $this->front()->get(self::HOST.'/')->assertOk();
        $home->assertSee('Summer drop')
            ->assertSee('Lightning deals')->assertSee('Sale Scarf')
            ->assertSee('HOLA10')->assertDontSee('OFF5')->assertDontSee('NOPE')   // only codes that work
            ->assertSee('10% off')
            ->assertSee('role="tablist"', false)->assertSee('Fresh')->assertSee('Deals')
            ->assertSee('Free returns')->assertSee('30-day returns')
            ->assertSee('https://img.example.com/ugc-1.jpg', false)->assertSee('Ana L.')
            ->assertSee('blk vis-mobile', false);

        // A flash sale whose time is up isn't shown
        $c['sections'][1]['settings']['ends_at'] = now()->subHour()->format('Y-m-d\TH:i');
        $this->saveAndPublish($d, $c);
        $this->front()->get(self::HOST.'/')->assertDontSee('Lightning deals');
    }

    public function test_bestsellers_follow_what_sells(): void
    {
        $this->product(['title' => 'Old Favourite']);
        $newest = $this->product(['title' => 'Brand New']);
        $fav = ProductVariant::withoutGlobalScope('store')->whereHas('product', fn ($q) => $q->where('title', 'Old Favourite'))->value('id');
        // Someone buys the older product
        $cart = $this->front()->post(self::HOST.'/cart/add', ['variant_id' => $fav, 'quantity' => 3])->getCookie('lumee_cart', false)->getValue();
        $rate = ShippingRate::withoutGlobalScope('store')->where('price', '>', 0)->first();
        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/checkout', ['email' => 'b@example.com', 'first_name' => 'B', 'last_name' => 'C',
            'address1' => '1 St', 'city' => 'Austin', 'zip' => '78701', 'country' => 'US', 'province' => 'TX', 'shipping_rate_id' => $rate->id, 'payment_gateway' => 'test'])->assertRedirect();
        $this->assertSame(1, Order::withoutGlobalScope('store')->count());

        $d = $this->editor();
        $c = $d['config'];
        $c['sections'] = [$this->block($d, 'market_bestsellers', ['heading' => 'Hot right now', 'limit' => 2], 'mb')];
        $this->saveAndPublish($d, $c);
        $html = $this->front()->get(self::HOST.'/')->assertOk()->assertSee('Hot right now')->getContent();
        $this->assertLessThan(strpos($html, 'Brand New'), strpos($html, 'Old Favourite'));   // sold first, then topped up with the newest
    }

    public function test_hotspots_only_use_this_stores_products(): void
    {
        $mine = $this->product(['title' => 'My Bag']);
        $b = $this->postJson('/api/admin/auth/register', ['name' => 'B', 'email' => 'b@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop B'])->json();
        $this->app['auth']->forgetGuards();
        $theirs = $this->withHeaders(['Authorization' => 'Bearer '.$b['token'], 'X-Store-Id' => (string) $b['stores'][0]['id']])
            ->postJson('/api/admin/products', ['title' => 'Their Bag', 'price' => 10, 'status' => 'active'])->json('data.id');

        $d = $this->editor();
        $c = $d['config'];
        $c['sections'] = [$this->block($d, 'hotspots', ['point1_product' => $mine, 'point2_product' => $theirs], 'hs')];
        $r = $this->as()->putJson('/api/admin/theme', [...$c, 'id' => $d['id']])->assertOk()->json();
        $this->assertSame($mine, $r['config']['sections'][0]['settings']['point1_product']);
        $this->assertNull($r['config']['sections'][0]['settings']['point2_product']);
    }

    public function test_collection_and_cart_pages_from_blocks(): void
    {
        $this->product(['title' => 'Rose Ring']);
        $d = $this->editor();
        $c = $d['config'];
        $c['templates']['collection'] = [
            $this->block($d, 'marquee', ['text' => 'Members get free shipping'], 'cm'),
            $this->block($d, 'collection_grid', ['columns' => '3', 'mobile_columns' => '1', 'show_sort' => false], 'cg'),
        ];
        $c['templates']['cart'] = [$this->block($d, 'cart_main', [], 'cart-m'), $this->block($d, 'trust_bar', ['returns' => 'Free 60-day returns'], 'cart-tb')];
        $r = $this->saveAndPublish($d, $c);
        $this->assertSame(['marquee', 'collection_grid'], array_column($r['config']['templates']['collection'], 'type'));   // no banner: the merchant removed it

        $this->front()->get(self::HOST.'/collections/all')->assertOk()->assertSee('Members get free shipping')
            ->assertSee('grid cols-3 m-cols-1', false)->assertDontSee('id="sort"', false)->assertSee('Rose Ring');
        $this->front()->get(self::HOST.'/cart')->assertOk()->assertSee('Free 60-day returns')->assertSee('Your bag');
    }

    public function test_campaign_landing_page(): void
    {
        $this->product(['title' => 'Rose Ring']);
        $page = $this->as()->postJson('/api/admin/pages', ['title' => 'Mothers Day', 'handle' => 'mothers-day', 'layout' => 'blocks', 'published' => true])->assertCreated()->json('data');
        $this->assertSame('blocks', $page['layout']);
        $this->assertNotNull($page['theme_id']);

        // Before it's designed: the default campaign blocks
        $this->front()->get(self::HOST.'/pages/mothers-day')->assertOk()->assertSee('<h1 class="sr-only">Mothers Day</h1>', false)->assertSee('Rose Ring');

        $d = $this->editor();
        $this->assertSame('page:'.$page['id'], $d['places'][4]['key']);
        $this->assertStringContainsString('/pages/mothers-day?_preview=', $d['places'][4]['preview_url']);
        $c = $d['config'];
        $this->assertSame(['hero', 'featured_products'], array_column($c['pages'][(string) $page['id']], 'type'));
        $c['pages'][(string) $page['id']] = [
            $this->block($d, 'rich_text', ['heading' => 'For the best mom', 'text' => 'Gifts she will love.'], 'md-rt'),
            $this->block($d, 'main_product', [], 'md-x'),   // product-page block → dropped
        ];
        $this->saveAndPublish($d, $c);
        $this->front()->get(self::HOST.'/pages/mothers-day')->assertOk()->assertSee('For the best mom')->assertDontSee('New Arrivals');

        // Text pages are unchanged
        $this->as()->postJson('/api/admin/pages', ['title' => 'About', 'handle' => 'about', 'body' => 'We make rings.', 'published' => true])->assertCreated();
        $this->front()->get(self::HOST.'/pages/about')->assertOk()->assertSee('We make rings.');
        // The landing page's texts can be translated
        $fields = collect($this->as()->getJson('/api/admin/translations?type=theme&locale=fr')->json('data.0.fields'));
        $this->assertTrue($fields->contains(fn ($f) => $f['key'] === 'sections.md-rt.heading'));
        $this->assertTrue($fields->contains(fn ($f) => str_starts_with($f['key'], 'sections.product-')));   // product page blocks too
    }

    public function test_style_settings_and_kits(): void
    {
        $d = $this->editor();
        $this->assertArrayHasKey('latam_promo', $d['schema']['kits']);
        $this->assertSame('theme', $d['config']['settings']['style']['buttons']);
        $this->front()->get(self::HOST.'/')->assertDontSee('.btn { border-radius: 999px; }', false);

        $c = $d['config'];
        $c['settings']['style'] = ['radius' => '20', 'buttons' => 'pill', 'cards' => 'shadow', 'image_ratio' => '1/1', 'density' => 'compact'];
        $this->saveAndPublish($d, $c);
        $this->front()->get(self::HOST.'/')->assertOk()
            ->assertSee('--radius-card: 20px', false)->assertSee('.btn { border-radius: 999px; }', false)
            ->assertSee('aspect-ratio: 1 / 1', false)->assertSee('padding-block: 36px', false);

        $c['settings']['style']['buttons'] = '</style><script>alert(1)</script>';   // not an option → the default
        $r = $this->saveAndPublish($d, $c);
        $this->assertSame('theme', $r['config']['settings']['style']['buttons']);
    }
}
