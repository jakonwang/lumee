<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ProductReview;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardHomeTest extends TestCase
{
    use RefreshDatabase;

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

    private function order(int $store, int $number, array $attrs = []): Order
    {
        return Order::withoutGlobalScope('store')->forceCreate([
            'store_id' => $store, 'number' => $number, 'token' => bin2hex(random_bytes(16)), 'email' => "b{$number}@x.test",
            'shipping_address' => ['name' => "Buyer {$number}"], 'payment_gateway' => 'manual', 'currency' => 'USD',
            'subtotal' => 2500, 'total' => 2500, 'financial_status' => 'paid', 'fulfillment_status' => 'unfulfilled', ...$attrs,
        ]);
    }

    public function test_todos_and_recent_orders(): void
    {
        $a = $this->register('a@example.com', 'Shop A');
        $b = $this->register('b@example.com', 'Shop B');
        $sid = $a['store_id'];

        $low = $this->as($a)->postJson('/api/admin/products', ['title' => 'Low', 'price' => 10, 'inventory' => 2, 'status' => 'active'])->json('data.id');
        $this->as($a)->postJson('/api/admin/products', ['title' => 'Plenty', 'price' => 10, 'inventory' => 50, 'status' => 'active']);
        $this->as($a)->postJson('/api/admin/products', ['title' => 'Draft', 'price' => 10, 'inventory' => 0, 'status' => 'draft']);
        ProductReview::withoutGlobalScope('store')->forceCreate(['store_id' => $sid, 'product_id' => $low, 'rating' => 5, 'author' => 'X', 'body' => 'Nice', 'status' => 'pending']);

        $this->order($sid, 1001);                                           // to fulfill
        $this->order($sid, 1002, ['financial_status' => 'pending']);        // awaiting payment
        $this->order($sid, 1003, ['dispute' => ['status' => 'needs_response']]);
        $this->order($sid, 1004, ['dispute' => ['status' => 'won'], 'fulfillment_status' => 'fulfilled']);
        $this->order($b['store_id'], 1001);                                 // other store

        $res = $this->as($a)->getJson('/api/admin/dashboard')->assertOk()
            ->assertJsonCount(4, 'recent_orders')
            ->assertJsonPath('recent_orders.0.number', 1004)
            ->assertJsonPath('recent_orders.0.customer', 'Buyer 1004')
            ->assertJsonPath('recent_orders.0.total', '25.00');
        $todos = collect($res->json('todos'))->pluck('count', 'key');
        $this->assertSame(2, $todos['to_fulfill']);
        $this->assertSame(1, $todos['awaiting_payment']);
        $this->assertSame(1, $todos['disputes']);
        $this->assertSame(0, $todos['abandoned']);
        $this->assertSame(1, $todos['low_stock']);   // drafts don't count
        $this->assertSame(1, $todos['pending_reviews']);

        // Second store sees only its own
        $this->assertSame(1, collect($this->as($b)->getJson('/api/admin/dashboard')->json('todos'))->pluck('count', 'key')['to_fulfill']);
        $this->assertSame(1, ProductVariant::withoutGlobalScope('store')->where('product_id', $low)->count());
    }
}
