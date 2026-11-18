<?php

namespace Tests\Feature;

use App\Currency\ExchangeRates;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MultiStoreTest extends TestCase
{
    use RefreshDatabase;

    private array $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake(config('lumee.media_disk'));
        $this->withoutMiddleware(ThrottleRequests::class);
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'U', 'email' => 'owner@multi.test', 'password' => 'secret-pass', 'store_name' => 'Store A'])->assertCreated();
        $this->owner = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
    }

    private function as(array $who, ?int $storeId = null): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$who['token'], 'X-Store-Id' => (string) ($storeId ?? $who['store_id']), 'Accept' => 'application/json']);
    }

    private function newStore(string $name, string $currency = 'USD'): int
    {
        return $this->as($this->owner)->postJson('/api/admin/stores', ['name' => $name, 'currency' => $currency])->assertCreated()->json('data.id');
    }

    public function test_owner_creates_another_store_and_sees_it_in_session(): void
    {
        $id = $this->newStore('Store B', 'EUR');
        $this->as($this->owner, $id)->getJson('/api/admin/me')->assertOk()->assertJsonCount(2, 'stores');
        $this->as($this->owner, $id)->getJson('/api/admin/products')->assertOk()->assertJsonCount(0, 'data');
        $this->as($this->owner)->postJson('/api/admin/stores', ['name' => ''])->assertStatus(422);
    }

    public function test_store_limit_per_account(): void
    {
        for ($i = 2; $i <= 10; $i++) {
            $this->newStore("Store $i");
        }
        $this->as($this->owner)->postJson('/api/admin/stores', ['name' => 'Eleven'])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_overview_sums_revenue_across_stores(): void
    {
        $b = $this->newStore('Store B');
        foreach ([[$this->owner['store_id'], 5000, 0], [$b, 3000, 1000]] as [$sid, $total, $refunded]) {
            Order::withoutGlobalScope('store')->forceCreate([
                'store_id' => $sid, 'number' => 1001, 'token' => bin2hex(random_bytes(16)), 'email' => 'x@y.test', 'shipping_address' => ['name' => 'X'], 'payment_gateway' => 'manual', 'currency' => 'USD', 'subtotal' => $total, 'total' => $total,
                'refunded_total' => $refunded, 'financial_status' => 'paid', 'fulfillment_status' => 'unfulfilled',
            ]);
        }
        $this->as($this->owner)->getJson('/api/admin/organization/overview?days=30&currency=USD')->assertOk()
            ->assertJsonCount(2, 'stores')->assertJsonPath('totals.revenue', '70.00')->assertJsonPath('totals.orders', 2)
            ->assertJsonCount(2, 'recent_orders');
    }

    public function test_copy_products_with_images_and_converted_prices(): void
    {
        $imageId = $this->as($this->owner)->post('/api/admin/uploads/images', ['file' => UploadedFile::fake()->image('a.jpg', 20, 20)])
            ->assertCreated()->json('data.id');
        $pid = $this->as($this->owner)->postJson('/api/admin/products', [
            'title' => 'Ring', 'status' => 'active', 'images' => [['id' => $imageId]],
            'options' => [['name' => 'Size', 'values' => ['S', 'M']]],
            'variants' => [['options' => ['Size' => 'S'], 'price' => 10, 'sku' => 'R-S', 'inventory' => 5], ['options' => ['Size' => 'M'], 'price' => 12, 'sku' => 'R-M', 'inventory' => 3]],
        ])->assertCreated()->json('data.id');

        $b = $this->newStore('Store B', 'EUR');
        $rates = $this->createMock(ExchangeRates::class);
        $rates->method('rate')->willReturnCallback(fn ($from, $to) => $from === $to ? 1.0 : 0.5);
        $this->app->instance(ExchangeRates::class, $rates);

        $res = $this->as($this->owner)->postJson('/api/admin/products/copy', [
            'product_ids' => [$pid], 'target_store_id' => $b, 'price_mode' => 'convert', 'inventory' => true, 'images' => true,
        ])->assertOk()->assertJsonPath('copied', 1);

        $copy = Product::withoutGlobalScope('store')->with([
            'variants' => fn ($q) => $q->withoutGlobalScope('store')->orderBy('position'),
            'images' => fn ($q) => $q->withoutGlobalScope('store'),
        ])->find($res->json('data.0.product_id'));
        $this->assertSame($b, $copy->store_id);
        $this->assertSame('draft', $copy->status);
        $this->assertSame([500, 600], $copy->variants->map(fn ($v) => (int) $v->getRawOriginal('price'))->all());
        $this->assertSame([5, 3], $copy->variants->map(fn ($v) => (int) $v->inventory_quantity)->all());
        $this->assertSame(['R-S', 'R-M'], $copy->variants->pluck('sku')->all());
        $this->assertCount(1, $copy->images);
        $img = $copy->images->first();
        $this->assertSame($b, $img->store_id);
        $this->assertStringStartsWith("stores/{$b}/products/", $img->path);
        Storage::disk(config('lumee.media_disk'))->assertExists($img->path);
        $this->assertNotSame(ProductImage::withoutGlobalScope('store')->find($imageId)->path, $img->path);

        // Source store untouched; each store only sees its own products
        $this->as($this->owner)->getJson('/api/admin/products')->assertOk()->assertJsonCount(1, 'data');
        $this->as($this->owner, $b)->getJson('/api/admin/products')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_copy_requires_access_to_target_store(): void
    {
        $pid = $this->as($this->owner)->postJson('/api/admin/products', ['title' => 'Ring', 'price' => 10])->assertCreated()->json('data.id');
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $other = $this->postJson('/api/admin/auth/register', ['name' => 'O', 'email' => 'other@multi.test', 'password' => 'secret-pass', 'store_name' => 'Other'])
            ->assertCreated()->json('stores.0.id');
        $this->as($this->owner)->postJson('/api/admin/products/copy', ['product_ids' => [$pid], 'target_store_id' => $other])
            ->assertStatus(422)->assertJsonValidationErrors('target_store_id');
        $this->as($this->owner)->postJson('/api/admin/products/copy', ['product_ids' => [$pid], 'target_store_id' => $this->owner['store_id']])
            ->assertStatus(422);
        $this->assertSame(1, Product::withoutGlobalScope('store')->count());
    }
}
