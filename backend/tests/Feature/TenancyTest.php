<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use RefreshDatabase;

    private function registerMerchant(string $email, string $storeName): array
    {
        $res = $this->postJson('/api/admin/auth/register', [
            'name' => 'Merchant',
            'email' => $email,
            'password' => 'secret-pass',
            'store_name' => $storeName,
        ])->assertCreated();

        return ['token' => $res['token'], 'store' => $res['stores'][0]];
    }

    private function asMerchant(array $m): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$m['token'],
            'X-Store-Id' => (string) $m['store']['id'],
        ]);
    }

    public function test_register_creates_store_with_subdomain(): void
    {
        $m = $this->registerMerchant('a@example.com', 'Aurora Jewels');

        $this->assertSame('aurora-jewels', $m['store']['handle']);
        $this->assertDatabaseHas('domains', ['host' => 'aurora-jewels.lumee.localhost', 'is_primary' => true]);
        $this->assertDatabaseHas('store_user', ['store_id' => $m['store']['id'], 'role' => 'owner']);
    }

    public function test_products_are_isolated_between_stores(): void
    {
        $a = $this->registerMerchant('a@example.com', 'Store A');
        $b = $this->registerMerchant('b@example.com', 'Store B');

        $productId = $this->asMerchant($a)->postJson('/api/admin/products', [
            'title' => 'Amethyst Necklace', 'price' => 129.5, 'inventory' => 5, 'status' => 'active',
        ])->assertCreated()->assertJsonPath('data.price', '129.50')->json('data.id');

        $this->asMerchant($a)->getJson('/api/admin/products')->assertJsonCount(1, 'data');

        // Store B sees nothing and cannot read/update/delete A's product by ID
        $this->asMerchant($b)->getJson('/api/admin/products')->assertJsonCount(0, 'data');
        $this->asMerchant($b)->getJson("/api/admin/products/{$productId}")->assertNotFound();
        $this->asMerchant($b)->putJson("/api/admin/products/{$productId}", ['title' => 'hacked'])->assertNotFound();
        $this->asMerchant($b)->deleteJson("/api/admin/products/{$productId}")->assertNotFound();
    }

    public function test_user_cannot_switch_into_a_store_they_do_not_belong_to(): void
    {
        $a = $this->registerMerchant('a@example.com', 'Store A');
        $b = $this->registerMerchant('b@example.com', 'Store B');

        $this->asMerchant(['token' => $b['token'], 'store' => $a['store']])
            ->getJson('/api/admin/products')
            ->assertForbidden();
    }

    public function test_storefront_resolves_store_from_host(): void
    {
        $a = $this->registerMerchant('a@example.com', 'Store A');
        $this->asMerchant($a)->postJson('/api/admin/products', [
            'title' => 'Rose Quartz Ring', 'price' => 89, 'inventory' => 3, 'status' => 'active',
        ])->assertCreated();

        $this->get('http://store-a.lumee.localhost/')->assertOk()->assertSee('Rose Quartz Ring')->assertSee('$89.00');
        $this->get('http://store-a.lumee.localhost/products/rose-quartz-ring')->assertOk()->assertSee('Rose Quartz Ring');
        $this->get('http://unknown.lumee.localhost/products/rose-quartz-ring')->assertNotFound();
        $this->get('http://localhost/')->assertOk()->assertSee('免费开店');
    }
}
