<?php

namespace Tests\Feature;

use App\Analytics\Visits;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\ShippingRate;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StorefrontAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private const UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

    private array $merchant;

    private int $productId;

    private int $variantId;

    /** Cookies of the simulated browser */
    private array $jar = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['lumee.payments.test_gateway' => true, 'services.stripe.secret' => null]);
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => 'a@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop A'])->assertCreated();
        $this->merchant = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
        $this->productId = $this->admin()->postJson('/api/admin/products', ['title' => 'Rose Ring', 'handle' => 'rose-ring', 'price' => 40, 'inventory' => 10, 'status' => 'active'])
            ->assertCreated()->json('data.id');
        $this->variantId = ProductVariant::withoutGlobalScope('store')->where('product_id', $this->productId)->value('id');
    }

    private function admin(): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->merchant['token'], 'X-Store-Id' => (string) $this->merchant['store_id'], 'Accept' => 'application/json']);
    }

    /** A browser request: keeps cookies between calls like a real browser */
    private function browse(string $method, string $path, array $data = [], array $headers = []): TestResponse
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->defaultCookies = $this->unencryptedCookies = [];
        $this->withoutMiddleware(ValidateCsrfToken::class);
        foreach ($this->jar as $k => $v) {
            $this->withUnencryptedCookie($k, $v);
        }
        $res = $this->withHeaders(['User-Agent' => self::UA, 'Accept-Language' => 'en-DE,en;q=0.9', ...$headers])
            ->{strtolower($method)}(self::HOST.$path, $data);
        foreach ($res->headers->getCookies() as $c) {
            $this->jar[$c->getName()] = $res->getCookie($c->getName(), false)->getValue();
        }

        return $res;
    }

    private function rate(): int
    {
        return ShippingRate::withoutGlobalScope('store')->where('name', 'like', 'Standard%')->where('price', '>', 0)->value('id');
    }

    public function test_a_visit_walks_through_the_funnel_and_converts(): void
    {
        $this->browse('GET', '/?utm_source=google&utm_medium=cpc&utm_campaign=spring', [], ['Referer' => 'https://www.google.com/'])->assertOk();
        $this->browse('GET', '/products/rose-ring')->assertOk();
        $this->browse('POST', '/cart/add', ['variant_id' => $this->variantId, 'quantity' => 1])->assertRedirect('/cart');
        $this->browse('GET', '/checkout')->assertOk();
        $this->browse('POST', '/checkout', [
            'email' => 'buyer@example.com', 'first_name' => 'Ada', 'last_name' => 'L', 'address1' => '1 Main St', 'city' => 'Berlin',
            'zip' => '10115', 'country' => 'DE', 'shipping_rate_id' => $this->rate(), 'payment_gateway' => 'test',
        ])->assertRedirect();

        $this->assertSame(1, DB::table('analytics_sessions')->count());
        $s = DB::table('analytics_sessions')->first();
        $this->assertSame('paid', $s->source);
        $this->assertSame('spring', $s->utm_campaign);
        $this->assertSame('google.com', $s->referrer_host);
        $this->assertSame('mobile', $s->device);
        $this->assertSame('Safari', $s->browser);
        $this->assertSame('DE', $s->country);           // estimated from Accept-Language (no CDN header)
        $this->assertSame(3, (int) $s->pageviews);      // home, product, checkout
        $this->assertTrue((bool) $s->viewed_product && (bool) $s->added_to_cart && (bool) $s->reached_checkout);
        $this->assertSame(Order::withoutGlobalScope('store')->value('id'), (int) $s->order_id);
        $this->assertDatabaseHas('analytics_product_stats', ['product_id' => $this->productId, 'views' => 1, 'carts' => 1]);

        // Another visitor who only looks around (direct, desktop)
        $this->jar = [];
        $this->browse('GET', '/products/rose-ring', [], ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36', 'CF-IPCountry' => 'FR'])->assertOk();

        $a = $this->admin()->getJson('/api/admin/analytics?days=7')->assertOk();
        $a->assertJsonPath('totals.sessions.value', 2)->assertJsonPath('totals.visitors.value', 2)
            ->assertJsonPath('totals.conversion.value', 50);
        $this->assertSame([2, 2, 1, 1, 1], collect($a->json('funnel'))->pluck('sessions')->all());
        $sources = collect($a->json('sources'))->keyBy('key');
        $this->assertSame(1, $sources['paid']['purchases']);
        $this->assertSame(0, $sources['direct']['purchases']);
        $countries = collect($a->json('countries'))->keyBy('code');
        $this->assertSame(1, $countries['DE']['orders']);
        $this->assertSame(1, $countries['FR']['sessions']);
        $product = collect($a->json('products'))->firstWhere('id', $this->productId);
        $this->assertSame([2, 1, 1], [$product['views'], $product['carts'], $product['qty']]);
        $this->assertSame('spring', $a->json('campaigns.0.key'));
        $a->assertJsonPath('customers.new.orders', 1)->assertJsonPath('live', 2);
        $this->assertCount(7, $a->json('series'));
        $this->assertSame(2, collect($a->json('series'))->sum('sessions'));
    }

    public function test_session_expires_after_inactivity_and_visitor_counts_as_returning(): void
    {
        $this->browse('GET', '/')->assertOk();
        $this->browse('GET', '/collections')->assertOk();
        $this->assertSame(1, DB::table('analytics_sessions')->count());

        $this->travel(Visits::SESSION_MINUTES + 1)->minutes();
        $this->browse('GET', '/')->assertOk();
        $this->assertSame(2, DB::table('analytics_sessions')->count());
        $this->assertTrue((bool) DB::table('analytics_sessions')->latest('id')->value('returning'));
        $this->assertSame(1, DB::table('analytics_sessions')->distinct()->count('visitor'));
    }

    public function test_bots_previews_and_other_stores_are_not_counted(): void
    {
        $this->browse('GET', '/', [], ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'])->assertOk();
        $this->browse('GET', '/', [], ['Sec-Purpose' => 'prefetch'])->assertOk();
        $this->assertSame(0, DB::table('analytics_sessions')->count());

        // Store B's analytics never see store A's traffic
        $this->browse('GET', '/')->assertOk();
        $this->flushHeaders();
        $other = $this->postJson('/api/admin/auth/register', ['name' => 'B', 'email' => 'b@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop B'])->json();
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$other['token'], 'X-Store-Id' => (string) $other['stores'][0]['id']])
            ->getJson('/api/admin/analytics')->assertOk()->assertJsonPath('totals.sessions.value', 0);
    }

    public function test_custom_range_and_validation(): void
    {
        $today = now()->toDateString();
        $this->admin()->getJson("/api/admin/analytics?from={$today}&to={$today}")->assertOk()
            ->assertJsonPath('granularity', 'hour')->assertJsonCount(24, 'series');
        $this->admin()->getJson('/api/admin/analytics?from=2025-01-01&to=2026-11-18')->assertStatus(422);
        $this->admin()->getJson('/api/admin/analytics?from=2026-11-10&to=2026-11-01')->assertStatus(422);
    }
}
