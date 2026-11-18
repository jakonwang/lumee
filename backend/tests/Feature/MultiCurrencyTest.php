<?php

namespace Tests\Feature;

use App\Currency\Presentment;
use App\Models\ProductVariant;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MultiCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const A = 'http://shop-a.lumee.localhost';

    private array $a;

    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.payments.test_gateway' => true]);
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $res = $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => 'a@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop A'])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
        $id = $this->as()->postJson('/api/admin/products', ['title' => 'Rose Ring', 'price' => 40, 'inventory' => 5, 'status' => 'active'])->json('data.id');
        $this->variantId = ProductVariant::withoutGlobalScope('store')->where('product_id', $id)->value('id');

        // First fetch succeeds, later ones fail (to prove old rates are kept)
        Http::fake(['open.er-api.com/*' => Http::sequence()
            ->push(['result' => 'success', 'rates' => ['USD' => 1, 'EUR' => 0.9, 'GBP' => 0.8, 'XYZ' => 3]])
            ->whenEmpty(Http::response('down', 500))]);
        $this->artisan('lumee:exchange-rates')->assertSuccessful();
    }

    private function as(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->a['token'], 'X-Store-Id' => (string) $this->a['store_id'], 'Accept' => 'application/json']);
    }

    private function front(): static
    {
        $this->flushHeaders();
        $this->defaultCookies = [];        // withCookie() persists across requests in tests
        $this->unencryptedCookies = [];
        $this->app['auth']->forgetGuards();

        return $this;
    }

    private function enable(array $extra = []): void
    {
        $this->as()->putJson('/api/admin/settings/currencies', [
            'enabled' => true, 'currencies' => ['EUR', 'GBP'], 'rounding' => '0.99', 'auto_detect' => true, ...$extra,
        ])->assertOk();
    }

    public function test_rates_are_fetched_and_a_failed_fetch_keeps_the_old_ones(): void
    {
        $this->assertSame(['EUR' => 0.9, 'GBP' => 0.8], DB::table('exchange_rates')->whereIn('currency', ['EUR', 'GBP'])->orderBy('currency')->pluck('per_usd', 'currency')->map(fn ($v) => (float) $v)->all());
        $this->assertFalse(DB::table('exchange_rates')->where('currency', 'XYZ')->exists());   // unsupported codes ignored

        $this->artisan('lumee:exchange-rates')->assertFailed();
        $this->assertSame(0.9, (float) DB::table('exchange_rates')->where('currency', 'EUR')->value('per_usd'));

        $this->as()->getJson('/api/admin/settings/currencies')->assertOk()
            ->assertJsonPath('store_currency', 'USD')
            ->assertJsonPath('settings.enabled', false)
            ->assertJsonPath('currencies.0.code', 'EUR')
            ->assertJsonPath('currencies.0.market_rate', 0.9);
    }

    public function test_rounding_rules(): void
    {
        $this->assertSame([3600, 3699, 3699, 3700, 3700, 0], [
            Presentment::applyRounding(3600, 'none'),
            Presentment::applyRounding(3601, '0.99'),
            Presentment::applyRounding(3699, '0.99'),
            Presentment::applyRounding(3601, '1.00'),
            Presentment::applyRounding(3700, '1.00'),
            Presentment::applyRounding(0, '0.99'),
        ]);
    }

    public function test_shopper_picks_a_currency_and_sees_converted_prices_but_pays_in_store_currency(): void
    {
        // Disabled: store currency only, no picker
        $this->front()->get(self::A.'/collections/all')->assertSee('$40.00')->assertDontSee('id="currency-select"', false);

        $this->enable();
        $this->front()->get(self::A.'/collections/all')->assertSee('$40.00')->assertSee('id="currency-select"', false);

        // Pick EUR → 40 × 0.9 = 36.00 → rounded to €36.99
        $res = $this->front()->post(self::A.'/currency', ['currency' => 'EUR'])->assertRedirect();
        $cookie = $res->getCookie(Presentment::COOKIE)->getValue();
        $this->front()->withCookie(Presentment::COOKIE, 'EUR')->get(self::A.'/collections/all')->assertSee('€36.99');
        $this->front()->withCookie(Presentment::COOKIE, 'EUR')->get(self::A.'/products/rose-ring')
            ->assertSee('€36.99')->assertSee('"currency":"EUR","rate":0.9', false);
        $this->assertNotEmpty($cookie);

        // A currency the store doesn't offer is ignored
        $this->front()->post(self::A.'/currency', ['currency' => 'CHF'])->assertCookieMissing(Presentment::COOKIE);
        $this->front()->withCookie(Presentment::COOKIE, 'CHF')->get(self::A.'/collections/all')->assertSee('$40.00');

        // Cart shows EUR with a clear note; checkout and the order stay in USD
        $cart = $this->front()->post(self::A.'/cart/add', ['variant_id' => $this->variantId, 'quantity' => 1])->getCookie('lumee_cart', false)->getValue();
        $this->front()->withCookie(Presentment::COOKIE, 'EUR')->withUnencryptedCookie('lumee_cart', $cart)->get(self::A.'/cart')
            ->assertSee('€36.99')->assertSee("You'll pay in USD")->assertSee('$40.00 before shipping', false);
        $this->front()->withCookie(Presentment::COOKIE, 'EUR')->withUnencryptedCookie('lumee_cart', $cart)->get(self::A.'/checkout')
            ->assertSee('Your order will be charged in USD')->assertSee('$40.00');
    }

    public function test_manual_rate_override_and_country_auto_detect(): void
    {
        $this->enable(['rates' => ['GBP' => 0.5]]);
        $html = $this->front()->withCookie(Presentment::COOKIE, 'GBP')->get(self::A.'/collections/all')->getContent();
        preg_match('#<div class="price">(.*?)</div>#s', $html, $m);
        $this->assertStringContainsString('£20.99', $m[1] ?? $html);

        // First visit from Germany (Cloudflare country header) → EUR preselected
        $this->front()->withHeader('CF-IPCountry', 'DE')->get(self::A.'/collections/all')->assertSee('€36.99');
        // …but not when the merchant turned auto-detect off
        $this->enable(['auto_detect' => false]);
        $this->front()->withHeader('CF-IPCountry', 'DE')->get(self::A.'/collections/all')->assertSee('$40.00');
    }

    public function test_settings_validation(): void
    {
        $this->as()->putJson('/api/admin/settings/currencies', ['enabled' => true, 'currencies' => [], 'rounding' => 'none'])->assertStatus(422);
        $this->as()->putJson('/api/admin/settings/currencies', ['enabled' => true, 'currencies' => ['XXX'], 'rounding' => 'none'])->assertStatus(422);
        $this->as()->putJson('/api/admin/settings/currencies', ['enabled' => true, 'currencies' => ['EUR'], 'rounding' => '0.50'])->assertStatus(422);
        $this->as()->putJson('/api/admin/settings/currencies', ['enabled' => true, 'currencies' => ['EUR'], 'rounding' => 'none', 'rates' => ['EUR' => -1]])->assertStatus(422);
        // The store's own currency is never listed as an extra
        $this->as()->putJson('/api/admin/settings/currencies', ['enabled' => true, 'currencies' => ['USD', 'EUR'], 'rounding' => 'none'])
            ->assertOk()->assertJsonPath('settings.currencies', ['EUR']);
    }
}
