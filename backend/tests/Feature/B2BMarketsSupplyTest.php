<?php

namespace Tests\Feature;

use App\Models\CollectedItem;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** B2B across markets (price lists, quick order, catalogs / feeds) and the supply side (suppliers, POs, portal). */
class B2BMarketsSupplyTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private array $a;

    private int $productId;

    private int $variant;

    private int $mx;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.payments.test_gateway' => true, 'lumee.storefront_scheme' => 'http', 'lumee.storefront_port' => null]);
        Mail::fake();
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => 'a@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop A'])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
        $this->productId = $this->as()->postJson('/api/admin/products', ['title' => 'Rose Ring', 'price' => 40, 'inventory' => 100, 'status' => 'active', 'sku' => 'RING-07'])->json('data.id');
        $this->variant = ProductVariant::withoutGlobalScope('store')->where('product_id', $this->productId)->value('id');
        Http::fake(['open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['USD' => 1, 'MXN' => 20]])]);
        $this->artisan('lumee:exchange-rates')->assertSuccessful();
        $this->mx = $this->as()->postJson('/api/admin/markets', ['name' => 'Mexico', 'handle' => 'mx', 'countries' => ['MX'], 'currency' => 'MXN',
            'default_locale' => 'es', 'locales' => ['en'], 'price_adjustment' => 0, 'rounding' => 'none', 'enabled' => true])->json('data.id');
    }

    private function as(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->a['token'], 'X-Store-Id' => (string) $this->a['store_id'], 'Accept' => 'application/json']);
    }

    private function shopper(): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        return $this;
    }

    private function businessBuyer(): void
    {
        $c = $this->as()->postJson('/api/admin/companies', ['name' => 'Acme', 'tag' => 'wholesale'])->assertCreated()->json('data');
        $this->shopper()->post(self::HOST.'/account/register', ['first_name' => 'Bo', 'last_name' => 'B', 'email' => 'bo@acme.test', 'password' => 'correct-horse', 'password_confirmation' => 'correct-horse']);
        $this->as()->postJson("/api/admin/companies/{$c['id']}/members", ['email' => 'bo@acme.test', 'role' => 'admin'])->assertOk();
        $this->shopper()->post(self::HOST.'/account/login', ['email' => 'bo@acme.test', 'password' => 'correct-horse']);
    }

    public function test_price_lists_per_market_and_quick_order(): void
    {
        // General list: $30 (converted in Mexico → MX$600); Mexico's own list: MX$500 with 10+ at MX$450
        $this->as()->postJson('/api/admin/price-lists', ['name' => 'Wholesale', 'customer_tag' => 'wholesale', 'percent_off' => 0, 'prices' => [['variant_id' => $this->variant, 'price' => 30]]])->assertCreated();
        $list = $this->as()->postJson('/api/admin/price-lists', ['name' => 'Mayoreo MX', 'customer_tag' => 'wholesale', 'percent_off' => 0, 'market_id' => $this->mx,
            'prices' => [['variant_id' => $this->variant, 'price' => 500, 'tiers' => [['min' => 10, 'price' => 450]]]]])->assertCreated()->json('data');
        $this->assertSame('MXN', $list['currency']);
        $this->as()->postJson('/api/admin/price-lists', ['name' => 'x', 'customer_tag' => 'x', 'percent_off' => 0, 'market_id' => 999])->assertJsonValidationErrors('market_id');

        $this->businessBuyer();
        $this->shopper()->get(self::HOST.'/products/rose-ring')->assertSee('$30.00');           // default site: the general list
        $this->shopper()->get(self::HOST.'/en-mx/products/rose-ring')->assertSee('500.00')->assertDontSee('600.00');   // Mexico: its own list, not converted

        // Quick order by SKU, in the Mexico market: 12 units → volume tier
        $this->shopper()->get(self::HOST.'/en-mx/wholesale/order')->assertOk()->assertSee('Quick order');
        $res = $this->shopper()->post(self::HOST.'/en-mx/wholesale/order', ['lines' => "RING-07, 12\nNOPE-1, 2\nnot a line"])
            ->assertRedirect('/en-mx/wholesale/order')->assertSessionHas('quick_order_errors', fn ($e) => count($e) === 2);
        $cartToken = $res->getCookie('lumee_cart', false)->getValue();
        $cart = DB::table('cart_items')->first();
        $this->assertSame(12, (int) $cart->quantity);
        $this->shopper()->withUnencryptedCookie('lumee_cart', $cartToken)->get(self::HOST.'/en-mx/cart')->assertSee('5,400.00');   // 12 × MX$450

        // Shoppers who aren't business buyers are sent to log in
        $this->shopper()->post(self::HOST.'/account/logout');
        $this->shopper()->get(self::HOST.'/wholesale/order')->assertRedirect('/account/login?return=/wholesale/order');
    }

    public function test_wholesale_catalog_page_and_distributor_feed(): void
    {
        $this->as()->postJson('/api/admin/price-lists', ['name' => 'Mayoreo MX', 'customer_tag' => 'mayoreo', 'percent_off' => 10, 'market_id' => $this->mx,
            'prices' => [['variant_id' => $this->variant, 'min_qty' => 6]]])->assertCreated();
        $listId = DB::table('price_lists')->value('id');
        $c = $this->as()->postJson('/api/admin/wholesale-catalogs', ['name' => 'Distribuidora Norte', 'market_id' => $this->mx, 'price_list_id' => $listId, 'show_stock' => true])
            ->assertCreated()->json('data');
        $this->assertStringContainsString('/wholesale/catalog/', $c['url']);

        // Page: retail MX$800, wholesale 10% off = MX$720, MOQ 6
        $page = $this->shopper()->get($c['url'])->assertOk();
        $page->assertDontSee('Distribuidora Norte')   // the catalog's internal name isn't shown to the prospect
            ->assertSee('Mexico')->assertSee('Rose Ring')->assertSee('RING-07')->assertSee('720.00')->assertSee('800.00');
        $json = $this->shopper()->getJson($c['json'])->assertOk();
        $this->assertSame('MXN', $json->json('currency'));
        $this->assertSame(['retail_price' => 80000, 'price' => 72000, 'min_qty' => 6, 'stock' => 100],
            array_intersect_key($json->json('products.0.variants.0'), array_flip(['retail_price', 'price', 'min_qty', 'stock'])));
        $csv = $this->shopper()->get($c['csv'])->assertOk()->streamedContent();
        $this->assertStringContainsString('RING-07', $csv);
        $this->assertStringContainsString('720.00', $csv);
        $this->assertSame(3, (int) DB::table('wholesale_catalogs')->value('views'));

        // A storefront request afterwards isn't left in the catalog's market or price list
        $this->shopper()->get(self::HOST.'/products/rose-ring')->assertSee('$40.00');

        // Expired or another store's token → 404
        DB::table('wholesale_catalogs')->update(['expires_at' => now()->subDay()]);
        $this->shopper()->get($c['url'])->assertNotFound();
    }

    public function test_distributors_order_through_the_catalog_api(): void
    {
        $this->as()->postJson('/api/admin/price-lists', ['name' => 'Mayoreo MX', 'customer_tag' => 'mayoreo', 'percent_off' => 10, 'market_id' => $this->mx,
            'prices' => [['variant_id' => $this->variant, 'min_qty' => 6]]])->assertCreated();
        $c = $this->as()->postJson('/api/admin/wholesale-catalogs', ['name' => 'Distribuidora Norte', 'market_id' => $this->mx, 'price_list_id' => DB::table('price_lists')->value('id'), 'show_stock' => true])
            ->assertCreated()->json('data');
        $this->assertNull($c['order_key']);
        $body = ['email' => 'compras@norte.mx', 'po_number' => 'PO-77', 'shipping_address' => ['first_name' => 'Ana', 'address1' => 'Av. 1', 'city' => 'Monterrey', 'country' => 'MX'],
            'items' => [['sku' => 'RING-07', 'quantity' => 6]]];

        // Off: the catalog token alone never places orders
        $this->shopper()->postJson($c['orders_url'], $body)->assertUnauthorized();
        $c = $this->as()->postJson("/api/admin/wholesale-catalogs/{$c['id']}/order-key", ['enabled' => true])->assertOk()->json('data');
        $this->assertStringStartsWith('ok_', $c['order_key']);
        $this->shopper()->withHeader('Authorization', 'Bearer wrong')->postJson($c['orders_url'], $body)->assertUnauthorized();
        $api = fn () => $this->shopper()->withHeader('Authorization', 'Bearer '.$c['order_key']);
        $this->assertStringNotContainsString($c['order_key'], $api()->getJson($c['json'])->getContent());   // the feed never shows the key

        // Below the minimum quantity, unknown SKU: rejected
        $api()->postJson($c['orders_url'], [...$body, 'items' => [['sku' => 'RING-07', 'quantity' => 2]]])->assertJsonValidationErrors('items.0.quantity');
        $api()->postJson($c['orders_url'], [...$body, 'items' => [['sku' => 'NOPE', 'quantity' => 6]]])->assertJsonValidationErrors('items.0');

        // 6 × MX$720 in pesos, pending, linked to the catalog and the Mexico market
        $res = $api()->postJson($c['orders_url'], $body)->assertCreated();
        $this->assertEquals(['currency' => 'MXN', 'subtotal' => 432000, 'financial_status' => 'pending', 'po_number' => 'PO-77'],
            array_intersect_key($res->json('order'), array_flip(['currency', 'subtotal', 'financial_status', 'po_number'])));
        $order = Order::withoutGlobalScope('store')->firstOrFail();
        $this->assertSame('wholesale_api', $order->source);
        $this->assertSame($this->mx, $order->market_id);
        $this->assertSame(20.0, $order->exchange_rate);
        $this->assertSame(94, (int) ProductVariant::withoutGlobalScope('store')->whereKey($this->variant)->value('inventory_quantity'));

        // Same PO again: the same order; status lookup
        $api()->postJson($c['orders_url'], $body)->assertOk()->assertJsonPath('order.number', $order->number);
        $this->assertSame(1, Order::withoutGlobalScope('store')->count());
        $api()->getJson($c['orders_url'].'/'.$order->number)->assertOk()->assertJsonPath('order.items.0.quantity', 6);
        $this->as()->getJson('/api/admin/wholesale-catalogs')->assertJsonPath('data.0.orders_count', 1);

        // Turning it off locks the old key out
        $this->as()->postJson("/api/admin/wholesale-catalogs/{$c['id']}/order-key", ['enabled' => false])->assertOk();
        $api()->getJson($c['orders_url'].'/'.$order->number)->assertUnauthorized();
    }

    public function test_lead_gets_a_catalog_in_its_market(): void
    {
        $lead = Lead::withoutGlobalScope('store')->forceCreate(['store_id' => $this->a['store_id'], 'source' => 'manual', 'source_id' => 'm-1', 'name' => 'Accesorios Monterrey', 'country' => 'MX', 'status' => 'new']);
        $first = $this->as()->postJson("/api/admin/leads/{$lead->id}/catalog")->assertCreated()->json('data');
        $this->assertSame($this->mx, $first['market_id']);
        $this->assertSame($first['id'], $this->as()->postJson("/api/admin/leads/{$lead->id}/catalog")->assertOk()->json('data.id'));   // reused
    }

    public function test_purchasing_from_suggestion_to_stock_with_the_supplier_portal(): void
    {
        // Sold 28 in 14 days → 2/day → 60 for 30 days; 100 in stock → nothing to order yet
        $supplier = $this->as()->postJson('/api/admin/suppliers', ['name' => 'Yiwu Jewelry Co', 'email' => 'sales@yiwu.test', 'platform' => '1688'])->assertCreated()->json('data');
        $this->as()->postJson("/api/admin/suppliers/{$supplier['id']}/products", ['product_ids' => [$this->productId]])->assertJsonPath('done', 1);
        ProductVariant::withoutGlobalScope('store')->whereKey($this->variant)->update(['inventory_quantity' => 10, 'cost' => 800]);
        $order = Order::withoutGlobalScope('store')->forceCreate(['store_id' => $this->a['store_id'], 'number' => 1001, 'token' => 'x', 'email' => 'b@x.test', 'currency' => 'USD',
            'subtotal' => 0, 'total' => 0, 'shipping_address' => ['country' => 'US'], 'payment_gateway' => 'test', 'financial_status' => 'paid', 'fulfillment_status' => 'unfulfilled', 'created_at' => now()->subDays(3)]);
        DB::table('order_items')->insert(['store_id' => $this->a['store_id'], 'order_id' => $order->id, 'product_id' => $this->productId, 'variant_id' => $this->variant, 'title' => 'Rose Ring', 'price' => 4000, 'quantity' => 28, 'total' => 112000]);

        $s = collect($this->as()->getJson('/api/admin/purchasing')->assertOk()->json('suggestions'))->firstWhere('variant_id', $this->variant);
        $this->assertSame(50, $s['suggested']);   // 60 needed − 10 in stock
        $this->assertSame($supplier['id'], $s['supplier_id']);
        $this->assertSame('8.00', $s['cost']);

        $po = $this->as()->postJson('/api/admin/purchase-orders', ['supplier_id' => $supplier['id'], 'note' => 'Gold colour only', 'lines' => [['variant_id' => $this->variant, 'quantity' => 50]]])
            ->assertCreated()->json('data');
        $this->assertSame(['PO-1001', 'draft', '400.00'], [$po['name'], $po['status'], $po['total']]);
        $this->as()->postJson("/api/admin/purchase-orders/{$po['id']}/action", ['action' => 'receive'])->assertJsonValidationErrors('status');   // not sent yet
        $this->as()->postJson("/api/admin/purchase-orders/{$po['id']}/action", ['action' => 'send'])->assertJsonPath('data.status', 'sent');
        $this->assertSame(0, collect($this->as()->getJson('/api/admin/purchasing')->json('suggestions'))->where('variant_id', $this->variant)->count());   // 50 inbound

        // Supplier portal: sees the order, confirms with a date, ships with tracking, offers a product
        $this->shopper()->get($supplier['portal_url'])->assertOk()->assertSee('PO-1001')->assertSee('Gold colour only')->assertDontSee('8.00');   // no costs shown
        $this->shopper()->from($supplier['portal_url'])->post(self::HOST."/supplier/".basename($supplier['portal_url'])."/orders/{$po['id']}", ['action' => 'confirm', 'expected_at' => now()->addDays(5)->toDateString()])->assertRedirect();
        $this->shopper()->from($supplier['portal_url'])->post(self::HOST."/supplier/".basename($supplier['portal_url'])."/orders/{$po['id']}", ['action' => 'ship', 'tracking' => 'YT123'])->assertRedirect();
        $p = PurchaseOrder::withoutGlobalScope('store')->find($po['id']);
        $this->assertSame(['shipped', 'YT123'], [$p->status, $p->tracking]);
        $this->shopper()->from($supplier['portal_url'])->post(self::HOST.'/supplier/'.basename($supplier['portal_url']).'/products', [
            'title' => 'Pearl Anklet', 'price' => 12.5, 'currency' => 'CNY', 'moq' => 50, 'images' => "https://cdn.example.com/a.jpg\nnot-a-url",
        ])->assertRedirect()->assertSessionHasNoErrors();
        $offer = CollectedItem::withoutGlobalScope('store')->where('platform', 'supplier')->firstOrFail();
        $this->assertSame(['Pearl Anklet', 'CNY', 1250, ['https://cdn.example.com/a.jpg']], [$offer->data['title'], $offer->data['currency'], $offer->data['variants'][0]['cost'], $offer->data['images']]);
        $this->assertSame('Yiwu Jewelry Co', $offer->checks['supplier']['name']);

        // Received → stock + cost updated, logged as a purchase
        $this->as()->postJson("/api/admin/purchase-orders/{$po['id']}/action", ['action' => 'receive'])->assertJsonPath('data.status', 'received');
        $this->assertSame(60, ProductVariant::withoutGlobalScope('store')->find($this->variant)->inventory_quantity);
        $this->assertDatabaseHas('inventory_adjustments', ['variant_id' => $this->variant, 'delta' => 50, 'reason' => 'purchase']);

        // New link → the old one stops working
        $this->as()->postJson("/api/admin/suppliers/{$supplier['id']}/link")->assertOk();
        $this->shopper()->get($supplier['portal_url'])->assertNotFound();
        $this->assertNotNull(Supplier::withoutGlobalScope('store')->first()->token);
    }
}
