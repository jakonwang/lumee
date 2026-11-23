<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Creator;
use App\Models\MarketProductExclusion;
use App\Models\ProductMarketFit;
use App\Models\ProductOpportunity;
use App\Models\ProductVariant;
use App\Models\SeoPage;
use App\Tenancy\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Traffic: ad catalog feeds per market, SEO landing pages per market + keyword, creators → affiliates. */
class TrafficTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private array $a;

    private array $mx;

    /** @var array<string, int> product ids by title */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.storefront_scheme' => 'http', 'lumee.storefront_port' => null]);
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => 'a@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop A'])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
        Http::fake(fn (Request $r) => $this->web($r));
        $this->artisan('lumee:exchange-rates')->assertSuccessful();
        $this->mx = $this->as()->postJson('/api/admin/markets', [
            'name' => 'Mexico', 'handle' => 'mx', 'countries' => ['MX'], 'currency' => 'MXN', 'default_locale' => 'es', 'locales' => [],
            'price_adjustment' => 0, 'rounding' => 'none', 'exchange_rate' => null, 'enabled' => true,
        ])->assertCreated()->json('data');
        foreach (['Silicone Phone Case Blue' => 40, 'Silicone Phone Case Red' => 30, 'Pearl Bracelet' => 90] as $title => $price) {
            $this->p[$title] = $this->as()->postJson('/api/admin/products', ['title' => $title, 'price' => $price, 'inventory' => 5, 'status' => 'active'])->json('data.id');
        }
    }

    private function as(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->a['token'], 'X-Store-Id' => (string) $this->a['store_id'], 'Accept' => 'application/json']);
    }

    private function front(): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this;
    }

    private function tenant(): void
    {
        app(CurrentStore::class)->set(\App\Models\Store::find($this->a['store_id']));
    }

    private function web(Request $r)
    {
        $json = fn ($d) => Http::response(json_encode($d), 200, ['Content-Type' => 'application/json']);
        if (str_contains($r->url(), 'open.er-api.com')) {
            return $json(['result' => 'success', 'rates' => ['USD' => 1, 'MXN' => 20]]);
        }
        if (str_contains($r->url(), 'serpapi.com')) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return $json(['organic_results' => str_contains($q['q'], 'youtube') ? [
                ['title' => 'TechReseñas MX - YouTube', 'link' => 'https://www.youtube.com/@techresenasmx', 'snippet' => '245K suscriptores · Reseñas de fundas y gadgets. contacto@techresenas.mx'],
                ['title' => 'Unboxing video', 'link' => 'https://www.youtube.com/watch?v=abc', 'snippet' => '...'],
            ] : [
                ['title' => 'Fundas Bonitas (@fundas.bonitas) • Instagram photos and videos', 'link' => 'https://www.instagram.com/fundas.bonitas/',
                    'snippet' => '38.2K followers · Reseñas de fundas para celular 📱 colabs: hola@fundasbonitas.mx'],
                ['title' => 'Tiny (@tiny.acc)', 'link' => 'https://www.instagram.com/tiny.acc/', 'snippet' => '420 followers'],
            ]]);
        }

        return Http::response('', 404);
    }

    public function test_feeds_for_google_meta_and_tiktok_in_each_market(): void
    {
        $blue = $this->p['Silicone Phone Case Blue'];
        ProductVariant::withoutGlobalScope('store')->where('product_id', $blue)->update(['cost' => 1000]);   // 75% margin
        $this->tenant();
        ProductMarketFit::create(['product_id' => $blue, 'market_id' => $this->mx['id'], 'score' => 82]);
        MarketProductExclusion::create(['market_id' => $this->mx['id'], 'product_id' => $this->p['Pearl Bracelet']]);
        app(CurrentStore::class)->set(null);

        $default = simplexml_load_string($this->front()->get(self::HOST.'/feeds/google.xml')->assertOk()->getContent());
        $this->assertCount(3, $default->channel->item);

        $mx = simplexml_load_string($this->front()->get(self::HOST.'/es-mx/feeds/google.xml')->assertOk()->getContent());
        $items = [];
        foreach ($mx->channel->item as $i) {
            $items[(string) $i->children('g', true)->title] = $i->children('g', true);
        }
        $this->assertCount(2, $items);   // the bracelet isn't sold in Mexico
        $this->assertSame('800.00 MXN', (string) $items['Silicone Phone Case Blue']->price);
        $this->assertSame('fit-high', (string) $items['Silicone Phone Case Blue']->custom_label_0);
        $this->assertSame('margin-high', (string) $items['Silicone Phone Case Blue']->custom_label_2);
        $this->assertSame('fit-unknown', (string) $items['Silicone Phone Case Red']->custom_label_0);
        $this->assertStringContainsString('/es-mx/products/', (string) $items['Silicone Phone Case Blue']->link);
        $this->assertSame('in_stock', (string) $items['Silicone Phone Case Red']->availability);

        $meta = simplexml_load_string($this->front()->get(self::HOST.'/es-mx/feeds/meta.xml')->assertOk()->getContent());
        $this->assertSame('in stock', (string) $meta->channel->item[0]->children('g', true)->availability);

        $csv = $this->front()->get(self::HOST.'/es-mx/feeds/tiktok.csv')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
        $this->assertSame('sku_id', $rows[0][0]);
        $this->assertCount(3, $rows);
        $this->assertContains('800.00 MXN', $rows[1]);

        $feeds = collect($this->as()->getJson('/api/admin/settings/tracking')->assertOk()->json('feeds'));
        $this->assertSame(['http://shop-a.lumee.localhost/feeds/google.xml', 'http://shop-a.lumee.localhost/es-mx/feeds/google.xml'], $feeds->pluck('url')->all());
        $this->assertSame('http://shop-a.lumee.localhost/es-mx/feeds/tiktok.csv', $feeds[1]['urls']['tiktok']);
        $this->assertSame('MXN', $feeds[1]['currency']);
    }

    public function test_seo_pages_from_research_ideas(): void
    {
        $this->tenant();
        $idea = ProductOpportunity::create(['market_id' => $this->mx['id'], 'keyword' => 'phone case', 'source' => 'manual', 'score' => 80]);
        ProductOpportunity::create(['market_id' => $this->mx['id'], 'keyword' => 'pearl bracelet', 'source' => 'manual', 'score' => 60]);
        app(CurrentStore::class)->set(null);

        $index = $this->as()->getJson('/api/admin/seo-pages')->assertOk();
        $this->assertSame(['phone case', 'pearl bracelet'], array_column($index->json('suggestions'), 'keyword'));

        // AI needs a key; without it the template writes the page in the market's language
        $this->as()->postJson('/api/admin/seo-pages', ['opportunity_ids' => [$idea->id], 'ai' => true])->assertStatus(422);
        $page = $this->as()->postJson('/api/admin/seo-pages', ['opportunity_ids' => [$idea->id]])->assertCreated()->json('data.0');
        $this->assertSame('es', $page['locale']);
        $this->assertSame('phone-case-mx', $page['slug']);
        $this->assertSame(2, $page['products_count']);
        $this->assertSame('http://shop-a.lumee.localhost/es-mx/s/phone-case-mx', $page['url']);
        $this->assertSame('draft', $page['status']);
        $this->assertNotContains('phone case', array_column($this->as()->getJson('/api/admin/seo-pages')->json('suggestions'), 'keyword'));

        // Drafts aren't public
        $this->front()->get(self::HOST.'/es-mx/s/phone-case-mx')->assertNotFound();

        // Edit: unsafe HTML is stripped; publish
        $detail = $this->as()->getJson('/api/admin/seo-pages/'.$page['id'])->assertOk()->json('data');
        $this->assertStringContainsString('Descubre', $detail['intro']);
        $this->as()->putJson('/api/admin/seo-pages/'.$page['id'], [
            'title' => 'Fundas de silicona | Shop A', 'meta_description' => 'Fundas', 'heading' => 'Fundas de silicona para celular', 'slug' => 'phone-case-mx',
            'intro' => '<p onclick="x()">Elige tu <strong>funda</strong></p><script>alert(1)</script><a href="https://evil.example">link</a>',
            'faq' => [['q' => '¿Qué material?', 'a' => 'Silicona suave.']], 'product_ids' => null, 'status' => 'published',
        ])->assertOk()->assertJsonPath('data.status', 'published')->assertJsonPath('data.intro', '<p>Elige tu <strong>funda</strong></p>link');

        $html = $this->front()->get(self::HOST.'/es-mx/s/phone-case-mx')->assertOk()->getContent();
        $this->assertStringContainsString('Fundas de silicona para celular', $html);
        $this->assertStringContainsString('Silicone Phone Case Blue', $html);
        $this->assertStringNotContainsString('Pearl Bracelet', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('"@type":"FAQPage"', $html);
        $this->assertStringNotContainsString('hreflang', $html);   // lives on this one site only

        // Another site redirects to the page's own site; the sitemap lists it once
        $this->front()->get(self::HOST.'/s/phone-case-mx')->assertRedirect('http://shop-a.lumee.localhost/es-mx/s/phone-case-mx');
        $sitemap = $this->front()->get(self::HOST.'/sitemap.xml')->assertOk()->getContent();
        $this->assertSame(1, substr_count($sitemap, '/s/phone-case-mx'));

        // A page with one matching product can't be published (thin content)
        $thin = $this->as()->postJson('/api/admin/seo-pages', ['keyword' => 'pearl bracelet'])->assertCreated()->json('data.0');
        $this->assertNull($thin['market_id']);
        $this->as()->postJson('/api/admin/seo-pages/publish', ['ids' => [$thin['id']], 'status' => 'published'])->assertOk()
            ->assertJsonPath('done', 0)->assertJsonCount(1, 'errors');
        $this->assertSame('draft', SeoPage::withoutGlobalScope('store')->find($thin['id'])->status);
    }

    public function test_creators_are_found_scored_contacted_and_become_affiliates(): void
    {
        $this->as()->postJson('/api/admin/creators/search', ['keywords' => ['reseñas fundas'], 'country' => 'MX', 'platforms' => ['instagram']])->assertStatus(422);
        $this->as()->putJson('/api/admin/lead-finder/settings', ['serpapi_key' => 'serp-test'])->assertOk();
        $this->as()->postJson('/api/admin/creators/search', ['keywords' => ['reseñas fundas'], 'country' => 'MX', 'platforms' => ['instagram', 'youtube']])
            ->assertOk()->assertJsonPath('found', 3)->assertJsonPath('new', 3);

        $list = collect($this->as()->getJson('/api/admin/creators')->assertOk()->json('data'))->keyBy('handle');
        $this->assertSame(['fundas.bonitas', 'techresenasmx', 'tiny.acc'], $list->keys()->all());   // best first
        $ig = $list['fundas.bonitas'];
        $this->assertSame(38200, $ig['followers']);
        $this->assertSame(['hola@fundasbonitas.mx'], $ig['emails']);
        $this->assertContains('腰部达人（1 万–10 万粉丝），性价比最高', $ig['score_reasons']);
        $this->assertSame('FUNDASBONITAS', $ig['suggested_code']);
        $this->assertSame(245000, $list['techresenasmx']['followers']);
        $this->assertSame('youtube', $list['techresenasmx']['platform']);

        // First message: Spanish template without AI, with the commission and code
        $msg = $this->as()->postJson('/api/admin/creators/'.$ig['id'].'/outreach')->assertOk()->json('data');
        $this->assertFalse($msg['ai']);
        $this->assertStringContainsString('10% de comisión', $msg['message']);
        $this->assertStringContainsString('FUNDASBONITAS', $msg['message']);

        $this->as()->patchJson('/api/admin/creators/'.$ig['id'], ['status' => 'contacted'])->assertOk()->assertJsonPath('data.status', 'contacted');

        // Agreed: an affiliate with their code and link
        $this->as()->postJson('/api/admin/creators/'.$ig['id'].'/affiliate', ['email' => 'hola@fundasbonitas.mx', 'commission_rate' => 12])->assertCreated()
            ->assertJsonPath('data.status', 'agreed')->assertJsonPath('data.affiliate.code', 'fundasbonitas')
            ->assertJsonPath('data.affiliate.link', 'http://shop-a.lumee.localhost/?ref=fundasbonitas');
        $a = Affiliate::withoutGlobalScope('store')->where('code', 'fundasbonitas')->first();
        $this->assertSame(12.0, $a->commission_rate);
        $this->assertSame($a->id, Creator::withoutGlobalScope('store')->where('handle', 'fundas.bonitas')->value('affiliate_id'));

        // A taken code is refused
        $this->as()->postJson('/api/admin/creators/'.$list['tiny.acc']['id'].'/affiliate', ['email' => 't@example.com', 'code' => 'FundasBonitas'])->assertStatus(422);
    }
}
