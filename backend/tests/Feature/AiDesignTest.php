<?php

namespace Tests\Feature;

use App\LeadFinder\LeadAi;
use App\Models\StoreThemeMarket;
use App\Models\ThemeVersion;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Phase D: AI design proposals (brief / assistant), preview, apply with rollback, review, localize, allowance. */
class AiDesignTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private array $a;

    private int $mx;

    /** @var array<int, array{prompt: string, schema: array}> */
    private array $calls = [];

    /** What the mocked Claude answers next. */
    private array $answer = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.storefront_scheme' => 'http', 'lumee.storefront_port' => null, 'services.anthropic.key' => 'test-key']);
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => 'a@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop A'])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
        Http::fake(['open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['USD' => 1, 'MXN' => 20, 'BRL' => 5]])]);
        $this->artisan('lumee:exchange-rates')->assertSuccessful();
        $this->mx = $this->market('Mexico', 'mx', 'MX', 'MXN', 'es');
        $this->mock(LeadAi::class, function ($m) {
            $m->shouldReceive('json')->andReturnUsing(function ($store, $prompt, $schema) {
                $this->calls[] = ['prompt' => $prompt, 'schema' => $schema];

                return $this->answer;
            });
        });
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
        $this->withoutMiddleware(ValidateCsrfToken::class);

        return $this;
    }

    private function market(string $name, string $handle, string $country, string $currency, string $locale): int
    {
        return $this->as()->postJson('/api/admin/markets', ['name' => $name, 'handle' => $handle, 'countries' => [$country], 'currency' => $currency,
            'default_locale' => $locale, 'locales' => [], 'price_adjustment' => 0, 'rounding' => 'none', 'enabled' => true])->assertCreated()->json('data.id');
    }

    private function pairs(array $kv): array
    {
        return array_map(fn ($k, $v) => ['key' => $k, 'value' => (string) $v], array_keys($kv), $kv);
    }

    public function test_brief_becomes_a_previewable_proposal_and_applies_with_rollback(): void
    {
        $necklace = $this->as()->postJson('/api/admin/products', ['title' => 'Heart Necklace', 'price' => 30, 'inventory' => 5, 'status' => 'active'])->json('data.id');
        $col = $this->as()->postJson('/api/admin/collections', ['title' => 'Necklaces', 'published' => true])->json('data.id');
        $this->as()->postJson('/api/admin/discounts', ['code' => 'MAMA10', 'type' => 'percentage', 'value' => 10, 'active' => true])->assertCreated();
        $theme = $this->as()->getJson("/api/admin/theme?market={$this->mx}")->assertOk()->json();
        $this->assertTrue($theme['ai']['ready']);

        $this->answer = [
            'reply' => '做好了母亲节首页。', 'summary' => ['首屏改成母亲节'], 'kit' => 'latam_promo',
            'settings' => [['path' => 'colors.accent', 'value' => '#F7B6C2'], ['path' => 'header.logo', 'value' => '5']],
            'sections' => [
                ['id' => '', 'type' => 'slideshow', 'enabled' => true, 'settings' => $this->pairs(['slide1_heading' => 'Regalos para mamá', 'slide1_text' => 'Collares que dicen te quiero.', 'slide1_link' => '/collections/necklaces', 'slide1_image' => '12345'])],
                ['id' => '', 'type' => 'featured_products', 'enabled' => true, 'settings' => $this->pairs(['heading' => 'Collares favoritos', 'collection' => $col])],
                ['id' => '', 'type' => 'coupon_bar', 'enabled' => true, 'settings' => $this->pairs(['code1' => 'MAMA10', 'code2' => 'INVENTED'])],
                ['id' => '', 'type' => 'hotspots', 'enabled' => true, 'settings' => $this->pairs(['point1_product' => $necklace, 'point2_product' => 999999])],
                ['id' => '', 'type' => 'main_product', 'enabled' => true, 'settings' => []],   // not a home-page block → dropped
            ],
        ];
        $r = $this->as()->postJson('/api/admin/theme/ai/propose', ['id' => $theme['id'], 'market' => $this->mx, 'place' => 'home', 'mode' => 'build',
            'request' => 'Página del Día de las Madres, cálida, collares primero'])->assertOk()->json();
        $this->assertSame('做好了母亲节首页。', $r['reply']);
        $this->assertContains('新增「轮播横幅」', $r['changes']);
        $this->assertStringContainsString('/es-mx/?_preview=', $r['preview_url']);
        $this->assertStringContainsString('&_proposal='.$r['token'], $r['preview_url']);
        $this->assertSame(1, $r['ai']['used']);
        $prompt = $this->calls[0]['prompt'];
        $this->assertStringContainsString('Spanish', $prompt);
        $this->assertStringContainsString('Heart Necklace', $prompt);
        $this->assertStringContainsString('MAMA10', $prompt);
        $this->assertStringContainsString('Página del Día de las Madres', $prompt);

        // Nothing saved yet: the draft and the live site are unchanged; the preview link shows the proposal
        $this->assertNull(StoreThemeMarket::withoutGlobalScope('store')->first());
        $preview = parse_url($r['preview_url']);
        $this->front()->get(self::HOST.$preview['path'].'?'.$preview['query'])->assertOk()->assertSee('Regalos para mamá')->assertSee('MAMA10');
        $this->front()->get(self::HOST.$preview['path'].'?'.explode('&', $preview['query'])[0])->assertOk()->assertDontSee('Regalos para mamá');   // draft preview
        $this->front()->get(self::HOST.'/es-mx/?_proposal='.$r['token'])->assertOk()->assertDontSee('Regalos para mamá');                     // needs the signed preview

        // Apply → the market's draft (own home page); the draft before is a version
        $d = $this->as()->postJson('/api/admin/theme/ai/apply', ['token' => $r['token']])->assertOk()->json();
        $this->assertTrue($d['market']['own_sections']);
        $home = collect($d['config']['sections']);
        $this->assertSame(['slideshow', 'featured_products', 'coupon_bar', 'hotspots'], $home->pluck('type')->all());
        $this->assertNull($home[0]['settings']['slide1_image']);                 // the AI can't set images
        $this->assertSame('/collections/necklaces', $home[0]['settings']['slide1_link']);
        $this->assertSame(['MAMA10', ''], [$home[2]['settings']['code1'], $home[2]['settings']['code2']]);   // only real codes
        $this->assertSame([$necklace, null], [$home[3]['settings']['point1_product'], $home[3]['settings']['point2_product']]);
        $this->assertSame('pill', $d['config']['settings']['style']['buttons']);   // the kit
        $this->assertSame('#F7B6C2', $d['config']['settings']['colors']['accent']);
        $this->assertNull($d['config']['settings']['header']['logo']);
        $v = ThemeVersion::withoutGlobalScope('store')->where('source', 'ai')->sole();
        $this->assertStringContainsString('Página del Día de las Madres', $v->note);
        $this->as()->postJson('/api/admin/theme/ai/apply', ['token' => $r['token']])->assertJsonValidationErrors('ai');   // used up
        $this->front()->get(self::HOST.'/es-mx')->assertDontSee('Regalos para mamá');   // still not published

        // Roll back to before the AI
        $back = $this->as()->postJson("/api/admin/theme/versions/{$v->id}/restore")->assertOk()->json();
        $this->assertNotContains('slideshow', array_column($back['config']['sections'], 'type'));
    }

    public function test_assistant_in_a_market_cannot_restructure_a_followed_page(): void
    {
        $this->as()->postJson('/api/admin/products', ['title' => 'Rose Ring', 'price' => 40, 'status' => 'active'])->assertCreated();
        $main = $this->as()->getJson('/api/admin/theme')->json();
        $product = $main['config']['templates']['product'];
        $related = collect($product)->firstWhere('type', 'related_products');
        $this->answer = ['reply' => 'ok', 'summary' => [], 'kit' => '', 'settings' => [], 'sections' => [
            ['id' => $related['id'], 'type' => 'related_products', 'enabled' => true, 'settings' => $this->pairs(['heading' => 'También te puede gustar'])],
            ['id' => '', 'type' => 'faq', 'enabled' => true, 'settings' => $this->pairs(['heading' => 'Added'])],
        ]];
        $r = $this->as()->postJson('/api/admin/theme/ai/propose', ['id' => $main['id'], 'market' => $this->mx, 'place' => 'product', 'mode' => 'edit', 'request' => 'Títulos en español'])->assertOk()->json();
        $this->assertStringContainsString('keep exactly the same blocks', $this->calls[0]['prompt']);
        $d = $this->as()->postJson('/api/admin/theme/ai/apply', ['token' => $r['token']])->assertOk()->json();
        $this->assertSame(array_column($product, 'type'), array_column($d['config']['templates']['product'], 'type'));   // nothing added
        $layer = StoreThemeMarket::withoutGlobalScope('store')->sole()->draft;
        $this->assertSame([$related['id'] => ['heading' => 'También te puede gustar']], $layer['section_overrides']);
        $this->assertNull($layer['sections']);
    }

    public function test_review_and_localize(): void
    {
        $br = $this->market('Brasil', 'br', 'BR', 'BRL', 'pt');
        $main = $this->as()->getJson('/api/admin/theme')->json();
        $hero = collect($main['config']['sections'])->firstWhere('type', 'hero');

        $this->answer = ['suggestions' => [['title' => '首屏加一个商品模块', 'detail' => '手机上第一屏没有商品。', 'block_id' => $hero['id']]]];
        $rv = $this->as()->postJson('/api/admin/theme/ai/review', ['id' => $main['id'], 'market' => $br, 'place' => 'home'])->assertOk()->json();
        $this->assertSame('首屏加一个商品模块', $rv['suggestions'][0]['title']);
        $this->assertIsArray($rv['issues']);

        $this->answer = ['summary' => ['改写成巴西葡语'], 'items' => [
            ['id' => $hero['id'], 'key' => 'heading', 'text' => 'Joias para todos os dias'],
            ['id' => $hero['id'], 'key' => 'image', 'text' => 'hack'],          // not a text field → ignored
            ['id' => 'settings', 'key' => 'header.announcement_text', 'text' => 'Frete grátis acima de R$ 495,00'],
        ]];
        $r = $this->as()->postJson('/api/admin/theme/ai/localize', ['id' => $main['id'], 'from' => null, 'to' => $br])->assertOk()->json();
        $this->assertSame(['改写成巴西葡语'], $r['notes']);
        $this->assertSame('Brasil', $r['market']['name']);
        $this->assertTrue($r['market']['own_sections']);
        $this->assertSame('Joias para todos os dias', collect($r['config']['sections'])->firstWhere('id', $hero['id'])['settings']['heading']);
        $this->assertSame('Frete grátis acima de R$ 495,00', $r['config']['settings']['header']['announcement_text']);
        $this->assertStringContainsString('R$', $this->calls[1]['prompt']);   // the local facts are given
        $this->assertSame(2, $r['ai']['used']);   // review + localize
    }

    public function test_ai_banner_background_uses_the_image_service_with_the_product_cards(): void
    {
        \Illuminate\Support\Facades\Storage::fake(config('lumee.media_disk'));
        $pid = $this->as()->postJson('/api/admin/products', ['title' => 'Rose Ring', 'price' => 40, 'inventory' => 5, 'status' => 'active'])->json('data.id');
        $im = imagecreatetruecolor(300, 300);
        ob_start();
        imagepng($im);
        $png = ob_get_clean();
        \Illuminate\Support\Facades\Storage::disk(config('lumee.media_disk'))->put('stores/x/ring.png', $png);
        \App\Models\ProductImage::withoutGlobalScope('store')->forceCreate(['store_id' => $this->a['store_id'], 'product_id' => $pid, 'disk' => config('lumee.media_disk'), 'path' => 'stores/x/ring.png', 'position' => 0]);
        $kit = ['positioning' => '', 'audience' => '', 'voice' => '', 'banned_words' => [], 'title_pattern' => '', 'title_min' => 20, 'title_max' => 80,
            'ratio' => '3:4', 'background' => '#F7F5F2', 'padding' => 8, 'min_images' => 1, 'sections' => [], 'min_score' => 0];
        $this->as()->putJson('/api/admin/content/brand-kit', [...$kit, 'image_ai_provider' => 'openai', 'image_ai_key' => 'sk-img', 'image_ai_quota' => 5])->assertOk();
        Http::fake(['api.openai.com/*' => Http::response(['data' => [['b64_json' => base64_encode($png)]]]), 'open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['USD' => 1]])]);

        $main = $this->as()->getJson('/api/admin/theme')->json();
        $this->answer = ['reply' => '', 'summary' => [], 'kit' => '', 'settings' => [], 'sections' => [
            ['id' => '', 'type' => 'slideshow', 'enabled' => true, 'settings' => $this->pairs(['slide1_heading' => 'Holiday gifts'])],
        ]];
        $r = $this->as()->postJson('/api/admin/theme/ai/propose', ['id' => $main['id'], 'place' => 'home', 'mode' => 'build', 'request' => 'Cozy holiday gifts', 'ai_banner' => true])->assertOk()->json();
        $d = $this->as()->postJson('/api/admin/theme/ai/apply', ['token' => $r['token']])->assertOk()->json();
        $slide = $d['config']['sections'][0]['settings'];
        $this->assertNotNull($slide['slide1_image']);
        $this->assertNotNull($slide['slide1_mobile_image']);
        Http::assertSent(fn ($req) => $req->url() === 'https://api.openai.com/v1/images/edits' && str_contains($req->body(), 'Keep the white product cards'));
        $this->assertSame(1, \App\Content\SceneImages::for(\App\Models\Store::find($this->a['store_id']))->status()['used']);
    }

    public function test_no_key_allowance_and_other_stores(): void
    {
        $main = $this->as()->getJson('/api/admin/theme')->json();
        $req = ['id' => $main['id'], 'place' => 'home', 'mode' => 'edit', 'request' => 'x'];
        $this->as()->postJson('/api/admin/theme/ai/propose', [...$req, 'place' => 'checkout'])->assertJsonValidationErrors('place');

        \App\Models\Store::whereKey($this->a['store_id'])->update(['settings' => json_encode(['design' => ['ai_quota' => 1]])]);
        $this->answer = ['reply' => '', 'summary' => [], 'kit' => '', 'settings' => [], 'sections' => []];
        $token = $this->as()->postJson('/api/admin/theme/ai/propose', $req)->assertOk()->json('token');
        $this->as()->postJson('/api/admin/theme/ai/propose', $req)->assertJsonValidationErrors('ai');   // allowance used up

        // Another store can't apply or preview it
        $b = $this->postJson('/api/admin/auth/register', ['name' => 'B', 'email' => 'b@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop B'])->json();
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$b['token'], 'X-Store-Id' => (string) $b['stores'][0]['id'], 'Accept' => 'application/json'])
            ->postJson('/api/admin/theme/ai/apply', ['token' => $token])->assertJsonValidationErrors('ai');

        config(['services.anthropic.key' => null]);
        \App\Models\Store::whereKey($this->a['store_id'])->update(['settings' => json_encode([])]);
        $this->as()->postJson('/api/admin/theme/ai/propose', $req)->assertJsonValidationErrors('ai');
    }
}
