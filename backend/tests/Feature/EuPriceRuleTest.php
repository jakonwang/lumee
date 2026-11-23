<?php

namespace Tests\Feature;

use App\Catalog\PriceHistory;
use App\Models\Market;
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

    public function test_market_fixed_prices_count_too(): void
    {
        $eu = Market::withoutGlobalScope('store')->where('handle', 'eu')->value('id');
        $this->travelTo(now()->subDays(40));
        $p = $this->as()->postJson('/api/admin/products', ['title' => 'Silk scarf', 'status' => 'active', 'price' => 80, 'inventory' => 5])->assertCreated()->json('data');
        $v = $p['variants'][0]['id'];
        $fixed = fn (?float $price, ?float $compare = null) => $this->as()->putJson("/api/admin/products/{$p['id']}/markets", ['markets' => [
            ['id' => $eu, 'sold' => true, 'prices' => [['variant_id' => $v, 'price' => $price, 'compare_at_price' => $compare]]],
        ]])->assertOk();

        // In Europe the scarf had a fixed €60 (two weeks ago), then followed the store price (€80) again, and now is fixed at €45 "was €90"
        $this->travelTo(now()->addDays(26));
        $fixed(60);
        $this->travelTo(now()->addDays(7));
        $fixed(null);
        $this->travelBack();
        $fixed(45, 90);

        $this->get(self::HOST.'/en-eu/products/silk-scarf')->assertOk()->assertSee('€45.00')
            ->assertSee('Lowest price in the 30 days before the discount: €60.00');
    }

    public function test_housekeeping_keeps_the_last_old_price(): void
    {
        $p = $this->as()->postJson('/api/admin/products', ['title' => 'Old ring', 'status' => 'active', 'price' => 80, 'inventory' => 5])->assertCreated()->json('data');
        $v = $p['variants'][0]['id'];
        $store = $this->a['store_id'];
        DB::table('variant_price_history')->where('product_variant_id', $v)->delete();
        foreach ([[500, 9000], [450, 8500], [420, 8000], [10, 7000]] as [$days, $price]) {
            DB::table('variant_price_history')->insert(['store_id' => $store, 'product_variant_id' => $v, 'price' => $price, 'created_at' => now()->subDays($days)]);
        }
        DB::table('assistant_questions')->insert([
            ['store_id' => $store, 'question' => 'old', 'reply' => 'r', 'created_at' => now()->subDays(200)],
            ['store_id' => $store, 'question' => 'new', 'reply' => 'r', 'created_at' => now()->subDays(5)],
        ]);

        $this->artisan('lumee:housekeeping')->assertSuccessful();
        // 500 and 450 days go; 420 (the price in effect at the cutoff) and the recent one stay
        $this->assertSame([8000, 7000], DB::table('variant_price_history')->where('product_variant_id', $v)->orderBy('created_at')->pluck('price')->map(fn ($x) => (int) $x)->all());
        $this->assertSame(['new'], DB::table('assistant_questions')->pluck('question')->all());

        // More than one batch of variants: every one is pruned (none skipped between batches)
        $now = now();
        $rows = [];
        for ($n = 0; $n < 620; $n++) {
            $rows[] = ['store_id' => $store, 'product_id' => $p['id'], 'title' => "V{$n}", 'price' => 1000, 'inventory_quantity' => 0, 'position' => $n + 1, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('product_variants')->insert($chunk);
        }
        $ids = DB::table('product_variants')->where('product_id', $p['id'])->where('id', '!=', $v)->pluck('id');
        DB::table('variant_price_history')->whereIn('product_variant_id', $ids)->delete();
        foreach ($ids->chunk(100) as $chunk) {
            DB::table('variant_price_history')->insert($chunk->flatMap(fn ($id) => [
                ['store_id' => $store, 'product_variant_id' => $id, 'price' => 900, 'created_at' => now()->subDays(500)],
                ['store_id' => $store, 'product_variant_id' => $id, 'price' => 800, 'created_at' => now()->subDays(450)],
            ])->values()->all());
        }
        $this->artisan('lumee:housekeeping')->assertSuccessful();
        $this->assertSame($ids->count(), DB::table('variant_price_history')->whereIn('product_variant_id', $ids)->count());   // one row left each
    }
}
