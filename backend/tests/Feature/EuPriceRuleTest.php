<?php

namespace Tests\Feature;

use App\Catalog\PriceHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** EU sites show the lowest price of the 30 days before a discount next to a struck-through price. */
class EuPriceRuleTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.storefront_scheme' => 'http', 'lumee.storefront_port' => null]);
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => 'a@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop A'])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
        Http::fake(['open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['USD' => 1, 'EUR' => 1]])]);
        $this->artisan('lumee:exchange-rates')->assertSuccessful();
        $this->as()->postJson('/api/admin/markets', ['name' => 'Europe', 'handle' => 'eu', 'countries' => ['FR', 'DE'], 'currency' => 'EUR',
            'default_locale' => 'en', 'locales' => [], 'price_adjustment' => 0, 'rounding' => 'none', 'enabled' => true])->assertCreated();
    }

    private function as(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->a['token'], 'X-Store-Id' => (string) $this->a['store_id'], 'Accept' => 'application/json']);
    }

    public function test_lowest_price_of_the_30_days_before_a_discount(): void
    {
        $this->travelTo(now()->subDays(60));
        $p = $this->as()->postJson('/api/admin/products', ['title' => 'Pearl ring', 'status' => 'active', 'price' => 80, 'inventory' => 5])->assertCreated()->json('data');
        $v = $p['variants'][0]['id'];
        $this->travelBack();
        $price = fn (float $price, ?float $compare = null) => $this->as()->putJson("/api/admin/products/{$p['id']}", [
            'variants' => [['id' => $v, 'price' => $price, 'compare_at_price' => $compare, 'inventory' => 5]],
        ])->assertOk();

        // 20 days ago it went up to 90 for a while, 10 days ago down to 70 (both inside the 30 days), now on sale at 50 "was 100"
        $this->travelTo(now()->subDays(20));
        $price(90);
        $this->travelTo(now()->addDays(10));
        $price(70);
        $this->travelBack();
        $price(50, 100);
        $this->assertSame([$v => 7000], PriceHistory::lowestBefore([$v]));

        $this->get(self::HOST.'/en-eu/products/pearl-ring')->assertOk()->assertSee('Lowest price in the 30 days before the discount')->assertSee('70.00');
        // Not an EU site (main site in USD): not shown
        $this->get(self::HOST.'/products/pearl-ring')->assertOk()->assertDontSee('Lowest price in the 30 days before the discount');

        // A price that's older than the window counts as the one in effect when it opened
        $this->assertSame(1, DB::table('variant_price_history')->where('product_variant_id', $v)->where('price', 8000)->count());
        $this->travel(25)->days();   // now the 90 and 70 changes are 35+ days before… but the window is anchored to when 50 started
        $this->assertSame([$v => 7000], PriceHistory::lowestBefore([$v]));
    }
}
