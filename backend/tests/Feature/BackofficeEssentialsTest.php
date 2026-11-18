<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InventoryAdjustment;
use App\Models\Order;
use App\Models\OrderNotification;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\UrlRedirect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class BackofficeEssentialsTest extends TestCase
{
    use RefreshDatabase;

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->register('a@example.com', 'Shop A');
        $this->b = $this->register('b@example.com', 'Shop B');
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

    private function product(array $m, string $title, int $inventory = 10, float $price = 25): array
    {
        $id = $this->as($m)->postJson('/api/admin/products', ['title' => $title, 'price' => $price, 'inventory' => $inventory, 'status' => 'active'])->json('data.id');

        return ['id' => $id, 'variant' => ProductVariant::withoutGlobalScope('store')->where('product_id', $id)->value('id')];
    }

    private function manualOrder(array $m, array $items, array $extra = [])
    {
        return $this->as($m)->postJson('/api/admin/orders/manual', [
            'email' => 'Buyer@Example.com', 'first_name' => 'Ada', 'last_name' => 'Lee', 'country' => 'US', 'city' => 'Austin',
            'items' => $items, 'payment' => 'paid', 'notify' => false, ...$extra,
        ]);
    }

    private function csv(\Illuminate\Testing\TestResponse $res): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $res->streamedContent());

        return array_map('str_getcsv', array_filter(preg_split('/\r\n|\n/', $text)));
    }

    public function test_manual_order_reserves_stock_records_payment_and_stays_quiet(): void
    {
        $p = $this->product($this->a, 'Ring', 5, 40);
        $res = $this->manualOrder($this->a, [['variant_id' => $p['variant'], 'quantity' => 2], ['variant_id' => $p['variant'], 'quantity' => 1, 'price' => 0]],
            ['shipping_name' => 'Courier', 'shipping_price' => 10, 'discount_type' => 'percent', 'discount_value' => 10, 'discount_reason' => 'VIP', 'note' => 'Phone order'])
            ->assertCreated()
            ->assertJsonPath('data.source', 'admin')
            ->assertJsonPath('data.financial_status', 'paid')
            ->assertJsonPath('data.subtotal', '80.00')
            ->assertJsonPath('data.discount_total', '8.00')
            ->assertJsonPath('data.shipping_total', '10.00')
            ->assertJsonPath('data.total', '82.00')
            ->assertJsonPath('data.discount_lines.0.title', 'VIP');

        $order = Order::withoutGlobalScope('store')->find($res->json('data.id'));
        $this->assertSame(2, (int) ProductVariant::withoutGlobalScope('store')->find($p['variant'])->inventory_quantity);
        $this->assertSame(0, OrderNotification::withoutGlobalScope('store')->where('order_id', $order->id)->count());   // notify = false
        $customer = Customer::withoutGlobalScope('store')->where('email', 'buyer@example.com')->first();
        $this->assertSame(1, $customer->orders_count);
        $this->assertSame(8200, (int) $customer->total_spent);

        $log = InventoryAdjustment::withoutGlobalScope('store')->where('order_id', $order->id)->get();
        $this->assertSame([-2, -1], $log->pluck('delta')->all());
        $this->assertSame('order', $log->first()->reason);

        // Not enough stock → rejected unless overselling is allowed; notify = true queues the buyer email
        $this->manualOrder($this->a, [['variant_id' => $p['variant'], 'quantity' => 3]])->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');
        $id = $this->manualOrder($this->a, [['variant_id' => $p['variant'], 'quantity' => 3]], ['allow_oversell' => true, 'payment' => 'pending', 'notify' => true])
            ->assertCreated()->assertJsonPath('data.financial_status', 'pending')->json('data.id');
        $this->assertTrue(OrderNotification::withoutGlobalScope('store')->where('order_id', $id)->where('type', 'order_confirmation')->exists());

        // Another store's variant can't be ordered
        $this->manualOrder($this->b, [['variant_id' => $p['variant'], 'quantity' => 1]])->assertStatus(422);
        $this->as($this->a)->getJson('/api/admin/orders/manual/variants?q=Ring')->assertJsonPath('data.0.title', 'Ring');
        $this->as($this->b)->getJson('/api/admin/orders/manual/variants?q=Ring')->assertJsonCount(0, 'data');
    }

    public function test_inventory_history_and_manual_adjustment(): void
    {
        $p = $this->product($this->a, 'Necklace', 10);
        $detail = $this->as($this->a)->getJson("/api/admin/products/{$p['id']}")->json('data');
        $detail['variants'][0]['inventory'] = 7;
        $this->as($this->a)->putJson("/api/admin/products/{$p['id']}", $detail)->assertOk();

        $this->as($this->a)->postJson("/api/admin/products/{$p['id']}/inventory", ['variant_id' => $p['variant'], 'adjustment' => -2, 'note' => 'Damaged'])
            ->assertOk()->assertJsonPath('data.inventory', 5);
        $this->as($this->a)->postJson("/api/admin/products/{$p['id']}/inventory", ['variant_id' => $p['variant'], 'available' => 20])
            ->assertJsonPath('data.inventory', 20);

        $hist = $this->as($this->a)->getJson("/api/admin/products/{$p['id']}/inventory")->assertOk()->json('data');
        $this->assertSame(['manual', 'manual', 'edit', 'created'], array_column($hist, 'reason'));
        $this->assertSame([15, -2, -3, 10], array_column($hist, 'delta'));
        $this->assertSame([20, 5, 7, 10], array_column($hist, 'quantity_after'));
        $this->assertSame('Damaged', $hist[1]['note']);
        $this->assertSame('M', $hist[1]['user']);

        // Other store: 404
        $this->as($this->b)->getJson("/api/admin/products/{$p['id']}/inventory")->assertNotFound();
        $this->as($this->b)->postJson("/api/admin/products/{$p['id']}/inventory", ['variant_id' => $p['variant'], 'adjustment' => 5])->assertNotFound();
    }

    public function test_order_export_follows_filters(): void
    {
        $p = $this->product($this->a, '=HYPERLINK("x")', 50);
        $this->manualOrder($this->a, [['variant_id' => $p['variant'], 'quantity' => 1]]);
        $this->manualOrder($this->a, [['variant_id' => $p['variant'], 'quantity' => 2]], ['payment' => 'pending']);

        $rows = $this->csv($this->as($this->a)->get('/api/admin/orders/export')->assertOk());
        $this->assertSame('Name', $rows[0][0]);
        $this->assertCount(3, $rows);
        $this->assertStringStartsWith("'=HYPERLINK", $rows[1][26]);   // formula neutralised

        $unpaid = $this->csv($this->as($this->a)->get('/api/admin/orders/export?status=unpaid'));
        $this->assertCount(2, $unpaid);
        $this->assertSame('pending', $unpaid[1][4]);
        $this->assertCount(1, $this->csv($this->as($this->b)->get('/api/admin/orders/export')));   // header only
    }

    public function test_customer_tags_filters_profile_and_export(): void
    {
        $p = $this->product($this->a, 'Ring', 50);
        $this->manualOrder($this->a, [['variant_id' => $p['variant'], 'quantity' => 1]]);
        $this->manualOrder($this->a, [['variant_id' => $p['variant'], 'quantity' => 1]]);
        $this->manualOrder($this->a, [['variant_id' => $p['variant'], 'quantity' => 1]], ['email' => 'one@example.com']);
        $buyer = Customer::withoutGlobalScope('store')->where('email', 'buyer@example.com')->first();
        $one = Customer::withoutGlobalScope('store')->where('email', 'one@example.com')->first();

        $this->as($this->a)->patchJson("/api/admin/customers/{$buyer->id}", ['tags' => ['VIP', ' vip ', 'Wholesale, EU'], 'note' => 'Prefers DHL', 'accepts_marketing' => true])
            ->assertOk()->assertJsonPath('data.tags', ['VIP', 'Wholesale EU'])->assertJsonPath('data.note', 'Prefers DHL')
            ->assertJsonPath('data.accepts_marketing', true)->assertJsonPath('data.marketing_source', 'admin')
            ->assertJsonCount(2, 'data.orders')->assertJsonPath('data.aov', '25.00');

        $this->as($this->a)->postJson('/api/admin/customers/bulk-tags', ['ids' => [$buyer->id, $one->id], 'tag' => 'Spring', 'action' => 'add'])->assertJsonPath('updated', 2);
        $this->as($this->a)->getJson('/api/admin/customers?tag=VIP')->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'buyer@example.com');
        $this->as($this->a)->getJson('/api/admin/customers?tag=Spring')->assertJsonCount(2, 'data');
        $this->as($this->a)->getJson('/api/admin/customers?orders=repeat')->assertJsonCount(1, 'data');
        $this->as($this->a)->getJson('/api/admin/customers?marketing=subscribed')->assertJsonCount(1, 'data');
        $this->as($this->a)->getJson('/api/admin/customers?sort=spent')->assertJsonPath('data.0.email', 'buyer@example.com');
        $tags = collect($this->as($this->a)->getJson('/api/admin/customers/tags')->json('data'))->pluck('count', 'tag');
        $this->assertSame(2, $tags['Spring']);

        $rows = $this->csv($this->as($this->a)->get('/api/admin/customers/export?tag=VIP'));
        $this->assertCount(2, $rows);
        $this->assertSame('buyer@example.com', $rows[1][2]);
        $this->assertStringContainsString('VIP', $rows[1][12]);

        // Isolation
        $this->as($this->b)->getJson("/api/admin/customers/{$buyer->id}")->assertNotFound();
        $this->as($this->b)->postJson('/api/admin/customers/bulk-tags', ['ids' => [$buyer->id], 'tag' => 'X', 'action' => 'add'])->assertJsonPath('updated', 0);
    }

    public function test_url_redirects(): void
    {
        $host = 'http://'.Store::find($this->a['store_id'])->handle.'.'.config('lumee.storefront_domain');
        $this->as($this->a)->postJson('/api/admin/redirects', ['path' => 'https://old-shop.com/Pages/About-Us/?ref=1', 'target' => '/pages/about'])
            ->assertCreated()->assertJsonPath('data.path', '/pages/about-us');
        $this->as($this->a)->postJson('/api/admin/redirects', ['path' => '/products/old-ring', 'target' => 'https://example.com/ring'])->assertCreated();
        $this->as($this->a)->postJson('/api/admin/redirects', ['path' => '/pages/about-us', 'target' => '/x'])->assertStatus(422)->assertJsonValidationErrors('path');
        $this->as($this->a)->postJson('/api/admin/redirects', ['path' => '/a', 'target' => 'javascript:alert(1)'])->assertJsonValidationErrors('target');
        $this->as($this->a)->postJson('/api/admin/redirects', ['path' => '/', 'target' => '/x'])->assertJsonValidationErrors('path');

        // Unknown path (fallback route) and a missing product (controller 404) both redirect
        $this->get($host.'/pages/About-Us')->assertStatus(301)->assertRedirect('/pages/about');
        $this->get($host.'/products/old-ring')->assertStatus(301)->assertRedirect('https://example.com/ring');
        $this->get($host.'/fr/pages/about-us')->assertStatus(301);
        $this->get($host.'/nothing-here')->assertNotFound();
        $this->assertSame(2, UrlRedirect::withoutGlobalScope('store')->where('path', '/pages/about-us')->value('hits'));

        // Another store doesn't get these
        $hostB = 'http://'.Store::find($this->b['store_id'])->handle.'.'.config('lumee.storefront_domain');
        $this->get($hostB.'/pages/about-us')->assertNotFound();

        // Shopify CSV import (header + update + bad row)
        $file = UploadedFile::fake()->createWithContent('redirects.csv', "Redirect from,Redirect to\n/collections/sale,/collections/summer\n/pages/about-us,/pages/story\nbad,javascript:x\n");
        $this->as($this->a)->post('/api/admin/redirects/import', ['file' => $file])->assertOk()
            ->assertJsonPath('created', 1)->assertJsonPath('updated', 1)->assertJsonPath('errors.0.line', 4);
        $this->get($host.'/pages/about-us')->assertRedirect('/pages/story');
        $this->as($this->a)->getJson('/api/admin/redirects?q=sale')->assertJsonCount(1, 'data');
        $this->as($this->b)->getJson('/api/admin/redirects')->assertJsonCount(0, 'data');
    }
}
