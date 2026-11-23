<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ShippingRate;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The admin lists merchants open all day run a fixed number of queries however many rows they show (no N+1).
 * A list that does a query per row gets slow exactly when a store grows — this catches it first.
 */
class AdminQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    /** Endpoints checked (the first page of each list, plus the dashboard and reports). */
    private const ENDPOINTS = [
        '/api/admin/orders', '/api/admin/products', '/api/admin/customers', '/api/admin/collections',
        '/api/admin/dashboard', '/api/admin/abandoned-checkouts', '/api/admin/returns', '/api/admin/reviews', '/api/admin/gift-cards',
        '/api/admin/subscriptions', '/api/admin/companies', '/api/admin/quotes', '/api/admin/promotions', '/api/admin/campaigns',
        '/api/admin/flows', '/api/admin/activity', '/api/admin/analytics', '/api/admin/design/performance',
    ];

    private array $h;

    private int $collection;

    /** @var array<string, list<string>> each endpoint's queries in the last run (for the failure message) */
    private array $sql = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.storefront_scheme' => 'http', 'lumee.storefront_port' => null, 'lumee.payments.test_gateway' => true]);
        $this->withoutMiddleware(ThrottleRequests::class);   // many orders in a few seconds
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => 'a@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop A'])->assertCreated();
        $this->h = ['Authorization' => 'Bearer '.$res['token'], 'X-Store-Id' => (string) $res['stores'][0]['id'], 'Accept' => 'application/json'];
        $this->collection = $this->admin()->postJson('/api/admin/collections', ['title' => 'Rings', 'published' => true])->json('data.id');
    }

    private function admin(): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders($this->h);
    }

    /** Products, each bought once by a different customer (orders, customers, line items, stock moves). */
    private function grow(int $from, int $to): void
    {
        $rate = null;
        for ($n = $from; $n <= $to; $n++) {
            $variant = $this->admin()->postJson('/api/admin/products', ['title' => "Ring {$n}", 'status' => 'active', 'price' => 40 + $n, 'inventory' => 9,
                'collection_ids' => [$this->collection]])->assertCreated()->json('data.variants.0.id');
            $this->flushHeaders();
            $this->app['auth']->forgetGuards();
            $cart = $this->withoutMiddleware(ValidateCsrfToken::class)->post(self::HOST.'/cart/add', ['variant_id' => $variant])->getCookie('lumee_cart', false)->getValue();
            $rate ??= ShippingRate::withoutGlobalScope('store')->first();
            $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/checkout', ['email' => "buyer{$n}@example.com", 'first_name' => 'B', 'last_name' => "N{$n}",
                'address1' => '1 St', 'city' => 'Austin', 'zip' => '78701', 'country' => 'US', 'province' => 'TX', 'shipping_rate_id' => $rate->id, 'payment_gateway' => 'test'])->assertRedirect();
            $this->defaultCookies = [];
            $this->unencryptedCookies = [];
        }
    }

    /** @return array<string, int> */
    private function queriesPerEndpoint(): array
    {
        $out = [];
        foreach (self::ENDPOINTS as $url) {
            $this->sql[$url] = [];
            $this->admin();
            DB::listen(function (QueryExecuted $q) use ($url) {
                if (! str_contains($q->sql, 'personal_access_tokens')) {   // sign-in bookkeeping ("last used"), depends on timing, not on rows
                    $this->sql[$url][] = preg_replace('/\b\d+\b/', '?', $q->sql);
                }
            });
            $res = $this->getJson($url);
            DB::getEventDispatcher()->forget(QueryExecuted::class);
            $this->assertTrue($res->isOk(), "{$url} answered {$res->status()}");
            $out[$url] = count($this->sql[$url]);
        }

        return $out;
    }

    public function test_admin_lists_do_not_query_per_row(): void
    {
        $this->grow(1, 4);
        $few = $this->queriesPerEndpoint();
        $fewSql = $this->sql;
        $this->grow(5, 16);
        $many = $this->queriesPerEndpoint();
        $this->assertGreaterThanOrEqual(16, Order::withoutGlobalScope('store')->count());

        foreach ($few as $url => $n) {
            $added = array_keys(array_diff_assoc(array_count_values($this->sql[$url]), array_count_values($fewSql[$url])));
            $this->assertLessThanOrEqual($n, $many[$url], "{$url}: {$n} queries with 4 orders but {$many[$url]} with 16 (a query per row?)\nNew or repeated: ".implode("\n", $added));
        }
    }
}
