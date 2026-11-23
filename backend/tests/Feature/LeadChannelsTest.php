<?php

namespace Tests\Feature;

use App\LeadFinder\SafeFetcher;
use App\LeadFinder\Sources\WebSearchSource;
use App\Models\Lead;
use App\Models\LeadSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Lead finder channels: Google web + public social profiles (SerpApi), Instagram Business Discovery, Hunter.io. */
class LeadChannelsTest extends TestCase
{
    use RefreshDatabase;

    private array $a;

    /** @var string[] */
    private array $serpQueries = [];

    protected function setUp(): void
    {
        parent::setUp();
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => 'a@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop A'])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
        $this->app->instance(SafeFetcher::class, new SafeFetcher(fn () => ['93.184.216.34']));   // public-looking DNS, no real network
        Http::fake(fn (Request $r) => $this->web($r));
    }

    private function as(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->a['token'], 'X-Store-Id' => (string) $this->a['store_id'], 'Accept' => 'application/json']);
    }

    private function web(Request $r)
    {
        $url = $r->url();
        $json = fn ($d, $status = 200) => Http::response(json_encode($d), $status, ['Content-Type' => 'application/json']);
        if (str_contains($url, 'serpapi.com')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $this->serpQueries[] = $q['q'];

            return $json(['organic_results' => match (true) {
                str_starts_with($q['q'], 'site:instagram.com') => [
                    ['title' => 'Case Queen MX (@casequeen.mx) • Instagram photos and videos', 'link' => 'https://www.instagram.com/casequeen.mx/',
                        'snippet' => '12.5K followers · Fundas al mayoreo 📦 ventas@casequeen.mx WhatsApp +52 55 1234 5678'],
                    ['title' => 'Post by someone', 'link' => 'https://www.instagram.com/p/Cxyz123/', 'snippet' => 'nice case'],
                    ['title' => 'Funda Lab (@fundalab)', 'link' => 'https://www.instagram.com/fundalab/', 'snippet' => '3,200 seguidores. Accesorios para celular.'],
                ],
                str_starts_with($q['q'], 'site:facebook.com') => [
                    ['title' => 'Accesorios Lupita | Facebook', 'link' => 'https://www.facebook.com/accesorioslupita/', 'snippet' => 'Mayoreo de accesorios. www.accesorioslupita.com.mx'],
                    ['title' => 'A post', 'link' => 'https://www.facebook.com/accesorioslupita/posts/123', 'snippet' => '...'],
                    ['title' => 'Group', 'link' => 'https://www.facebook.com/groups/fundas/', 'snippet' => '...'],
                ],
                default => [
                    ['title' => 'Mayorista de Fundas | CelularPro', 'link' => 'https://www.celularpro.mx/mayoreo', 'snippet' => 'Venta al mayoreo de fundas y micas.'],
                    ['title' => 'Fundas - Amazon.com.mx', 'link' => 'https://www.amazon.com.mx/s?k=fundas', 'snippet' => '...'],
                    ['title' => 'Mejores mayoristas - Yelp', 'link' => 'https://www.yelp.com/search?x', 'snippet' => '...'],
                ],
            }]);
        }
        if (str_contains($url, 'graph.facebook.com')) {
            return str_contains(urldecode($url), 'username(fundalab)')
                ? $json(['business_discovery' => ['name' => 'Funda Lab', 'username' => 'fundalab', 'biography' => 'Accesorios al mayoreo · hola@fundalab.mx',
                    'website' => 'https://fundalab.mx/', 'followers_count' => 3200, 'media_count' => 410], 'id' => '1'])
                : $json(['error' => ['message' => 'Invalid user id', 'code' => 110]], 400);
        }
        if (str_contains($url, 'api.hunter.io')) {
            return $json(['data' => ['emails' => [
                ['value' => 'compras@celularpro.mx', 'type' => 'generic', 'confidence' => 92],
                ['value' => 'ana.lopez@celularpro.mx', 'type' => 'personal', 'confidence' => 88, 'first_name' => 'Ana', 'last_name' => 'López', 'position' => 'Purchasing Manager'],
                ['value' => 'maybe@celularpro.mx', 'type' => 'personal', 'confidence' => 30],
            ]]]);
        }
        if (preg_match('#https?://(www\.)?(celularpro\.mx|fundalab\.mx|accesorioslupita\.com\.mx)#', $url)) {
            return Http::response('<html><head><title>Wholesale phone cases</title></head><body>Mayoreo de fundas y accesorios para celular.</body></html>', 200, ['Content-Type' => 'text/html']);
        }

        return Http::response('', 404);
    }

    public function test_results_become_leads_only_when_they_are_companies(): void
    {
        $ig = WebSearchSource::fromResult('instagram', ['title' => 'Case Queen MX (@casequeen.mx) • Instagram photos and videos', 'link' => 'https://www.instagram.com/casequeen.mx/?hl=es',
            'snippet' => '12.5K followers · ventas@casequeen.mx · WhatsApp +52 55 1234 5678 · casequeen.com.mx'], 'MX', 'Monterrey');
        $this->assertSame('Case Queen MX', $ig['name']);
        $this->assertSame('instagram:casequeen.mx', $ig['source_id']);
        $this->assertSame('https://www.instagram.com/casequeen.mx/', $ig['socials']['instagram']);
        $this->assertSame('https://wa.me/525512345678', $ig['socials']['whatsapp']);
        $this->assertSame(['ventas@casequeen.mx'], $ig['emails']);
        $this->assertSame('https://casequeen.com.mx', $ig['website']);
        $this->assertSame(12500, $ig['followers']);
        $this->assertSame('Monterrey', $ig['city']);

        $this->assertNull(WebSearchSource::fromResult('instagram', ['title' => 'x', 'link' => 'https://www.instagram.com/reel/abc/'], 'MX', null));
        $this->assertNull(WebSearchSource::fromResult('facebook', ['title' => 'x', 'link' => 'https://www.facebook.com/shop/posts/1'], 'MX', null));
        $this->assertNull(WebSearchSource::fromResult('facebook', ['title' => 'x', 'link' => 'https://www.facebook.com/groups/abc/'], 'MX', null));
        $this->assertSame('https://www.facebook.com/p/Shop-1000/', WebSearchSource::fromResult('facebook', ['title' => 'Shop | Facebook', 'link' => 'https://www.facebook.com/p/Shop-1000/'], 'MX', null)['socials']['facebook']);
        $this->assertSame('LinkedIn', WebSearchSource::fromResult('linkedin', ['title' => 'Distribuidora Norte | LinkedIn', 'link' => 'https://mx.linkedin.com/company/distribuidora-norte'], 'MX', null)['category']);
        $this->assertNull(WebSearchSource::fromResult('linkedin', ['title' => 'Ana', 'link' => 'https://www.linkedin.com/in/ana'], 'MX', null));
        $this->assertSame('tiktok:fundasmx', WebSearchSource::fromResult('tiktok', ['title' => 'Fundas MX (@fundasmx) | TikTok', 'link' => 'https://www.tiktok.com/@fundasmx/video/123'], 'MX', null)['source_id']);
        $this->assertNull(WebSearchSource::fromResult('web', ['title' => 'Amazon', 'link' => 'https://www.amazon.com.mx/s?k=x'], 'MX', null));
        $this->assertNull(WebSearchSource::fromResult('web', ['title' => 'FB', 'link' => 'https://facebook.com/x'], 'MX', null));
        $this->assertSame('https://www.celularpro.mx', WebSearchSource::fromResult('web', ['title' => 'CelularPro', 'link' => 'https://www.celularpro.mx/mayoreo'], 'MX', null)['website']);
    }

    public function test_search_needs_a_serpapi_key_and_channels(): void
    {
        $body = ['industry' => 'custom', 'keywords' => ['fundas mayoreo'], 'country' => 'MX', 'cities' => [], 'source' => 'web', 'channels' => ['instagram']];
        $this->as()->postJson('/api/admin/lead-searches', $body)->assertStatus(422);
        $this->as()->putJson('/api/admin/lead-finder/settings', ['serpapi_key' => 'serp-test'])->assertOk()->assertJsonPath('settings.serp_ready', true);
        $this->as()->postJson('/api/admin/lead-searches', [...$body, 'channels' => []])->assertStatus(422)->assertJsonValidationErrors('channels');
        $this->as()->postJson('/api/admin/lead-searches', [...$body, 'channels' => ['myspace']])->assertStatus(422);
    }

    public function test_web_and_social_search_with_instagram_and_hunter_enrichment(): void
    {
        $this->as()->putJson('/api/admin/lead-finder/settings', [
            'serpapi_key' => 'serp-test', 'meta_token' => 'meta-test', 'ig_user_id' => '17841400000000000', 'hunter_key' => 'hunter-test', 'ai_auto' => false,
        ])->assertOk()
            ->assertJsonPath('settings.instagram_ready', true)->assertJsonPath('settings.hunter_ready', true)
            ->assertJsonMissing(['meta-test'])->assertJsonMissing(['hunter-test']);

        $this->as()->postJson('/api/admin/lead-searches', [
            'industry' => 'custom', 'keywords' => ['fundas mayoreo'], 'country' => 'MX', 'cities' => ['Monterrey'], 'source' => 'web', 'channels' => ['web', 'instagram', 'facebook'],
        ])->assertCreated()->assertJsonPath('data.channels', ['web', 'instagram', 'facebook']);

        $this->assertSame(['fundas mayoreo Monterrey', 'site:facebook.com fundas mayoreo Monterrey', 'site:instagram.com fundas mayoreo Monterrey'], $this->serpQueries);
        $search = LeadSearch::withoutGlobalScope('store')->first();
        $this->assertSame('done', $search->status, (string) $search->error);
        $leads = Lead::withoutGlobalScope('store')->get()->keyBy('source_id');
        $this->assertEqualsCanonicalizing(['celularpro.mx', 'instagram:casequeen.mx', 'instagram:fundalab', 'facebook:accesorioslupita'], $leads->keys()->all());

        // Website found by Google: Hunter adds the work emails and the people
        $web = $leads['celularpro.mx'];
        $this->assertSame('compras@celularpro.mx', $web->emails[0]);
        $this->assertNotContains('maybe@celularpro.mx', $web->emails);
        $this->assertSame('Purchasing Manager', $web->contacts[0]['position']);

        // Instagram business profile: website and bio email from Business Discovery, then the site is read
        $ig = $leads['instagram:fundalab'];
        $this->assertSame('https://fundalab.mx/', $ig->website);
        $this->assertSame('fundalab.mx', $ig->domain);
        $this->assertSame(3200, $ig->followers);
        $this->assertContains('hola@fundalab.mx', $ig->emails);
        $this->assertSame('done', $ig->enrich_status);

        // Not a business account: what the snippet said is kept, with a note
        $queen = $leads['instagram:casequeen.mx'];
        $this->assertSame(12500, $queen->followers);
        $this->assertSame('https://wa.me/525512345678', $queen->socials['whatsapp']);
        $this->assertStringContainsString('不是商业账号', (string) $queen->enrich_error);
        $this->assertContains('社媒粉丝 12,500', $queen->score_reasons);

        // Facebook page with a website in its snippet
        $this->assertSame('accesorioslupita.com.mx', $leads['facebook:accesorioslupita']->domain);
        $this->assertSame('https://www.facebook.com/accesorioslupita/', $leads['facebook:accesorioslupita']->socials['facebook']);

        // The list endpoint uses MySQL JSON functions; the detail endpoint returns the same row fields
        $this->as()->getJson('/api/admin/leads/'.$queen->id)->assertOk()->assertJsonPath('data.followers', 12500);
        $this->as()->getJson('/api/admin/leads/'.$web->id)->assertOk()->assertJsonPath('data.contacts.0.name', 'Ana López');
    }

    public function test_connection_tests_for_the_new_services(): void
    {
        $this->as()->postJson('/api/admin/lead-finder/settings/test', ['service' => 'serpapi'])->assertOk()->assertJsonPath('ok', false);
        $this->as()->putJson('/api/admin/lead-finder/settings', ['serpapi_key' => 'serp-test', 'hunter_key' => 'h'])->assertOk();
        $this->as()->postJson('/api/admin/lead-finder/settings/test', ['service' => 'serpapi'])->assertOk()->assertJsonPath('ok', true);
        $this->as()->postJson('/api/admin/lead-finder/settings/test', ['service' => 'hunter'])->assertOk()->assertJsonPath('ok', true);
        $this->as()->postJson('/api/admin/lead-finder/settings/test', ['service' => 'instagram'])->assertOk()->assertJsonPath('ok', false);
        // Clearing the SerpApi key
        $this->as()->putJson('/api/admin/lead-finder/settings', ['clear_serpapi_key' => true])->assertOk()->assertJsonPath('settings.serp_ready', false);
    }
}
