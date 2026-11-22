<?php

namespace Tests\Feature;

use App\LeadFinder\LeadAi;
use App\Models\Product;
use App\Models\ProductOpportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Products › Research radar: ideas from site signals, SerpApi evidence, score, AI analysis. */
class ResearchRadarTest extends TestCase
{
    use RefreshDatabase;

    private array $a;

    private int $storeId;

    protected function setUp(): void
    {
        parent::setUp();
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => 'a@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop A'])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
        $this->storeId = (int) $res['stores'][0]['id'];
        DB::table('exchange_rates')->insert([['currency' => 'MXN', 'per_usd' => 20, 'updated_at' => now()]]);
    }

    private function as(?array $m = null): static
    {
        $m ??= $this->a;
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$m['token'], 'X-Store-Id' => (string) $m['store_id'], 'Accept' => 'application/json']);
    }

    private function mexico(): int
    {
        return $this->as()->postJson('/api/admin/markets', ['name' => 'Mexico', 'handle' => 'mx', 'countries' => ['MX'], 'currency' => 'MXN',
            'default_locale' => 'es', 'locales' => [], 'price_adjustment' => 0, 'rounding' => 'none', 'enabled' => true])->json('data.id');
    }

    private function serpApi(): void
    {
        Http::fake(function (Request $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            if (($q['api_key'] ?? '') !== 'serp-key') {
                return Http::response(['error' => 'Invalid API key.'], 401);
            }

            return match ($q['engine']) {
                // 8 weekly points: interest doubles in the last 4 weeks
                'google_trends' => Http::response(['interest_over_time' => ['timeline_data' => array_map(fn ($v) => ['values' => [['extracted_value' => $v]]], [20, 20, 20, 20, 40, 40, 40, 40])]]),
                'google_shopping' => Http::response(['shopping_results' => [
                    ['title' => 'Tobillera plata', 'price' => '$299.00', 'extracted_price' => 299, 'source' => 'Liverpool'],
                    ['title' => 'Tobillera', 'price' => '$399.00', 'extracted_price' => 399, 'source' => 'Amazon.com.mx'],
                    ['title' => 'Tobillera 2', 'price' => '$799.00', 'extracted_price' => 799, 'source' => 'Amazon.com.mx'],
                ]]),
            };
        });
    }

    public function test_ideas_come_from_what_shoppers_searched_for_and_what_tests_well(): void
    {
        $mx = $this->mexico();
        $d = now()->subDays(2)->toDateString();
        DB::table('search_terms')->insert([
            ['store_id' => $this->storeId, 'market_id' => $mx, 'date' => $d, 'term' => 'tobillera de plata', 'searches' => 6, 'zero_results' => 6, 'results' => 0],
            ['store_id' => $this->storeId, 'market_id' => 0, 'date' => $d, 'term' => 'rose ring', 'searches' => 9, 'zero_results' => 0, 'results' => 4],
            ['store_id' => $this->storeId, 'market_id' => 0, 'date' => $d, 'term' => 'once', 'searches' => 1, 'zero_results' => 0, 'results' => 2],
        ]);
        $pid = $this->as()->postJson('/api/admin/products', ['title' => 'Layered Necklace', 'price' => 30, 'inventory' => 5, 'status' => 'active', 'product_type' => 'Necklace'])->json('data.id');
        Product::withoutGlobalScope('store')->whereKey($pid)->update(['test_status' => 'hot']);

        $this->as()->postJson('/api/admin/research/discover')->assertOk()->assertJsonPath('created', 3);
        $this->as()->postJson('/api/admin/research/discover')->assertOk()->assertJsonPath('created', 0);   // no duplicates

        $rows = collect($this->as()->getJson('/api/admin/research')->assertOk()->json('data'))->keyBy('keyword');
        $this->assertSame(['tobillera de plata', 'rose ring', 'necklace'], array_values(array_intersect(['tobillera de plata', 'rose ring', 'necklace'], $rows->keys()->all())));
        $this->assertSame('search_gap', $rows['tobillera de plata']['source']);
        $this->assertSame('Mexico', $rows['tobillera de plata']['market']);
        $this->assertSame('top_search', $rows['rose ring']['source']);
        $this->assertSame('hot_type', $rows['necklace']['source']);
        $this->assertNotNull($rows['tobillera de plata']['score']);   // demand from our own searches
        $this->assertSame(1, $rows['tobillera de plata']['scores']['evidence']);
        $this->assertFalse($rows->has('once'));
        $this->assertStringContainsString('1688', $rows['tobillera de plata']['links'][0]['url']);

        // Manual ideas and status changes; dropped ones leave the open list
        $this->as()->postJson('/api/admin/research/opportunities', ['keywords' => "Pearl Anklet\nPEARL ANKLET\nboho rings", 'market_id' => $mx])->assertCreated()->assertJsonPath('created', 2);
        $id = ProductOpportunity::withoutGlobalScope('store')->where('keyword', 'boho rings')->value('id');
        $this->as()->putJson("/api/admin/research/opportunities/{$id}", ['status' => 'dropped', 'note' => 'Too seasonal'])->assertOk();
        $this->assertFalse(collect($this->as()->getJson('/api/admin/research')->json('data'))->pluck('id')->contains($id));
        $this->assertSame(1, $this->as()->getJson('/api/admin/research?status=dropped')->json('meta.total'));
    }

    public function test_serpapi_evidence_and_ai_analysis_raise_the_score_with_reasons(): void
    {
        $mx = $this->mexico();
        $this->as()->postJson('/api/admin/products', ['title' => 'Anklet', 'price' => 20, 'inventory' => 5, 'status' => 'active']);
        $this->as()->postJson('/api/admin/research/opportunities', ['keywords' => 'tobillera de plata', 'market_id' => $mx])->assertCreated();
        $id = ProductOpportunity::withoutGlobalScope('store')->value('id');

        // No key yet → explained
        $this->as()->postJson('/api/admin/research/enrich', ['ids' => [$id]])->assertJsonValidationErrors('ids');
        $this->serpApi();
        $this->as()->putJson('/api/admin/research/settings', ['serpapi_key' => 'wrong'])->assertOk()->assertJsonPath('serpapi_connected', true);
        $this->as()->postJson('/api/admin/research/enrich', ['ids' => [$id]])->assertOk()->assertJsonPath('done', 0)->assertJsonPath('errors.0.message', 'SerpApi Key 无效');
        $this->as()->putJson('/api/admin/research/settings', ['serpapi_key' => 'serp-key'])->assertOk();
        $raw = json_decode(DB::table('stores')->where('id', $this->storeId)->value('settings'), true);
        $this->assertNotSame('serp-key', $raw['radar']['serpapi_key']);   // encrypted

        $this->as()->postJson('/api/admin/research/enrich', ['ids' => [$id]])->assertOk()->assertJsonPath('done', 1);
        $o = ProductOpportunity::withoutGlobalScope('store')->find($id);
        $this->assertSame(100.0, (float) $o->signals['trend']['growth']);
        $this->assertSame(2, $o->signals['shopping']['sellers']);
        $this->assertSame('MXN', $o->signals['shopping']['currency']);
        $this->assertSame(39900, $o->signals['shopping']['median']);
        $this->assertSame(40000, $o->signals['shopping']['store_median']);   // our $20 anklet in pesos
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'engine=google_shopping') && str_contains($r->url(), 'gl=mx') && str_contains($r->url(), 'hl=es'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'engine=google_trends') && str_contains($r->url(), 'geo=MX'));
        $this->assertSame(100, $o->scores['trend']);
        $this->assertSame(95, $o->scores['competition']);
        $this->assertGreaterThan(95, $o->scores['price']);
        $before = $o->score;

        // AI analysis adds the fit score
        config(['services.anthropic.key' => 'test-key']);
        $this->mock(LeadAi::class, fn ($m) => $m->shouldReceive('json')->andReturn([
            'fit_score' => 90, 'summary' => '墨西哥夏季需求高。', 'price_band' => ['min' => 249, 'max' => 449], 'local_keywords' => ['tobillera plata'],
            'title_ideas' => ['Tobillera de plata 925'], 'risks' => [], 'season' => '3–8 月', 'source_keywords_zh' => '925银脚链', 'source_keywords_en' => 'silver anklet',
        ]));
        $this->as()->postJson('/api/admin/research/analyze', ['ids' => [$id]])->assertOk()->assertJsonPath('done', 1);
        $row = $this->as()->getJson('/api/admin/research')->json('data.0');
        $this->assertSame(90, $row['scores']['fit']);
        $this->assertSame('MXN', $row['ai']['currency']);
        $this->assertSame(5, $row['scores']['evidence']);
        $this->assertGreaterThan($before, $row['score']);
        $this->assertStringContainsString(rawurlencode('925银脚链'), $row['links'][0]['url']);   // 1688 search in Chinese
        $this->as()->postJson('/api/admin/research/analyze', ['ids' => range(1, 6)])->assertJsonValidationErrors('ids');   // ≤ 5 at a time
    }

    public function test_keyword_exports_from_other_tools_become_evidence(): void
    {
        $mx = $this->mexico();
        $csv = "\xEF\xBB\xBFHashtag,Posts,Popularity change,Avg price\n#tobillera,\"1.2M\",+85%,$299\nanillo plata,12K,-20%,\ntobillera,999,10%,\n";
        $this->as()->postJson('/api/admin/research/import', ['csv' => $csv, 'source' => 'TikTok Creative Center', 'market_id' => $mx])
            ->assertCreated()->assertJson(['created' => 2, 'total' => 2]);   // duplicate keyword counted once
        $o = \App\Models\ProductOpportunity::withoutGlobalScope('store')->where('keyword', 'tobillera')->firstOrFail();
        $this->assertSame('import', $o->source);
        $this->assertEquals(['source' => 'TikTok Creative Center', 'volume' => 1200000, 'growth' => 85.0, 'price' => 29900, 'sellers' => null],
            array_intersect_key($o->signals['external'], array_flip(['source', 'volume', 'growth', 'price', 'sellers'])));
        $this->assertSame(100, $o->scores['demand']);
        $this->assertSame(91, $o->scores['trend']);   // +85% growth
        $falling = \App\Models\ProductOpportunity::withoutGlobalScope('store')->where('keyword', 'anillo plata')->firstOrFail();
        $this->assertLessThan($o->score, $falling->score);

        // A plain list works too; garbage doesn't
        $this->as()->postJson('/api/admin/research/import', ['csv' => "silver anklet\ngold hoops", 'source' => 'Keepa', 'market_id' => 0])->assertJson(['total' => 2]);
        $this->as()->postJson('/api/admin/research/import', ['csv' => " \n ", 'source' => 'Keepa', 'market_id' => 0])->assertJsonValidationErrors('csv');
        $this->as()->postJson('/api/admin/research/import', ['csv' => 'x', 'source' => 'Keepa', 'market_id' => 999])->assertJsonValidationErrors('market_id');
    }

    public function test_research_is_per_store(): void
    {
        $this->as()->postJson('/api/admin/research/opportunities', ['keywords' => 'silver anklet', 'market_id' => 0])->assertCreated();
        $id = ProductOpportunity::withoutGlobalScope('store')->value('id');
        $b = $this->postJson('/api/admin/auth/register', ['name' => 'B', 'email' => 'b@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop B'])->json();
        $mb = ['token' => $b['token'], 'store_id' => $b['stores'][0]['id']];
        $this->as($mb)->getJson('/api/admin/research')->assertJsonPath('meta.total', 0);
        $this->as($mb)->putJson("/api/admin/research/opportunities/{$id}", ['status' => 'won'])->assertNotFound();
        $this->as($mb)->deleteJson("/api/admin/research/opportunities/{$id}")->assertNotFound();
        $this->as($mb)->postJson('/api/admin/research/opportunities', ['keywords' => 'x', 'market_id' => 999])->assertJsonValidationErrors('market_id');
    }
}
