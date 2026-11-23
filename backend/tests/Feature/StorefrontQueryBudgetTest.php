<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Storefront pages run a fixed number of queries however many products they show (no N+1),
 * and stay within a budget — so a slow page shows up here before it shows up in production.
 */
class StorefrontQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    /** path => most queries allowed with the page cache off (first visit, nothing cached) */
    private const BUDGET = ['/' => 20, '/collections/rings' => 26, '/collections/all' => 24, '/products/ring-3' => 32, '/search?q=ring' => 25, '/cart' => 16];

    private array $h;

    private int $collection;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.storefront_scheme' => 'http', 'lumee.storefront_port' => null, 'lumee.page_cache.enabled' => false]);
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => 'a@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop A'])->assertCreated();
        $this->h = ['Authorization' => 'Bearer '.$res['token'], 'X-Store-Id' => (string) $res['stores'][0]['id'], 'Accept' => 'application/json'];
        $this->collection = $this->withHeaders($this->h)->postJson('/api/admin/collections', ['title' => 'Rings', 'published' => true])->json('data.id');
    }

    private function addProducts(int $from, int $to): void
    {
        for ($n = $from; $n <= $to; $n++) {
            $this->withHeaders($this->h)->postJson('/api/admin/products', ['title' => "Ring {$n}", 'status' => 'active', 'product_type' => 'Rings',
                'options' => [['name' => 'Size', 'values' => ['6', '7']]],
                'variants' => [['options' => ['Size' => '6'], 'price' => 40 + $n, 'inventory' => 3], ['options' => ['Size' => '7'], 'price' => 40 + $n, 'inventory' => 0]],
                'collection_ids' => [$this->collection]])->assertCreated();
        }
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
    }

    /** @return array<string, int> queries per page */
    private function queriesPerPage(): array
    {
        $out = [];
        foreach (array_keys(self::BUDGET) as $path) {
            $n = 0;
            DB::listen(function () use (&$n) {
                $n++;
            });
            $this->withHeader('User-Agent', 'Mozilla/5.0 Chrome/130')->get(self::HOST.$path)->assertOk();
            DB::getEventDispatcher()->forget(QueryExecuted::class);
            $out[$path] = $n;
        }

        return $out;
    }

    public function test_query_count_does_not_grow_with_products_and_stays_in_budget(): void
    {
        $this->addProducts(1, 6);
        $few = $this->queriesPerPage();
        $this->addProducts(7, 18);
        $many = $this->queriesPerPage();

        foreach (self::BUDGET as $path => $budget) {
            // Never more queries for more products (it can be fewer: e.g. "you may also like" needs a top-up query when the store is small)
            $this->assertLessThanOrEqual($few[$path], $many[$path], "{$path}: {$few[$path]} queries with 6 products but {$many[$path]} with 18 (a query per product?)");
            $this->assertLessThanOrEqual($budget, $many[$path], "{$path} runs {$many[$path]} queries (budget {$budget})");
        }
    }
}
