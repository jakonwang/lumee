<?php

namespace Tests\Feature;

use App\Models\Market;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\ShippingRate;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Markets: country sites with their own currency (charged at checkout), languages, URL, prices and range. */
class MarketsTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private array $a;

    private int $productId;

    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.payments.test_gateway' => true, 'services.stripe.secret' => null, 'lumee.storefront_scheme' => 'http', 'lumee.storefront_port' => null]);

        $res = $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => 'a@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop A'])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
        $this->productId = $this->as()->postJson('/api/admin/products', ['title' => 'Rose Ring', 'price' => 40, 'inventory' => 5, 'status' => 'active'])->json('data.id');
        $this->variantId = ProductVariant::withoutGlobalScope('store')->where('product_id', $this->productId)->value('id');

        Http::fake(['open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['USD' => 1, 'MXN' => 20, 'EUR' => 0.9, 'BRL' => 5]])]);
        $this->artisan('lumee:exchange-rates')->assertSuccessful();
    }

    private function as(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->a['token'], 'X-Store-Id' => (string) $this->a['store_id'], 'Accept' => 'application/json']);
    }

    private function front(?string $cart = null): static
    {
        $this->flushHeaders();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->app['auth']->forgetGuards();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        return $cart ? $this->withUnencryptedCookie('lumee_cart', $cart) : $this;
    }

    private function mexico(array $extra = []): array
    {
        return $this->as()->postJson('/api/admin/markets', [
            'name' => 'Mexico', 'handle' => 'mx', 'countries' => ['MX'], 'currency' => 'MXN',
            'default_locale' => 'es', 'locales' => ['en'], 'price_adjustment' => 0, 'rounding' => '0.99',
            'exchange_rate' => null, 'enabled' => true, ...$extra,
        ])->assertCreated()->json('data');
    }

    private function checkout(string $prefix, string $cart, array $extra = []): \Illuminate\Testing\TestResponse
    {
        $rate = ShippingRate::withoutGlobalScope('store')->where('price', '>', 0)->first();

        return $this->front($cart)->post(self::HOST.$prefix.'/checkout', [
            'email' => 'buyer@example.com', 'first_name' => 'Ana', 'last_name' => 'López', 'address1' => 'Av. Reforma 1',
            'city' => 'CDMX', 'province' => 'CDMX', 'zip' => '06600', 'country' => 'MX',
            'shipping_rate_id' => $rate->id, 'payment_gateway' => 'test', ...$extra,
        ]);
    }

    public function test_markets_are_validated(): void
    {
        $m = $this->mexico();
        $this->assertEquals(20, $m['rate']);
        $this->assertSame(['es', 'en'], $m['locales']);   // default language first
        $this->assertStringEndsWith('/es-mx/', $m['url']);

        $base = ['name' => 'X', 'countries' => ['FR'], 'currency' => 'EUR', 'default_locale' => 'fr', 'locales' => [], 'price_adjustment' => 0, 'rounding' => 'none', 'enabled' => true];
        $this->as()->postJson('/api/admin/markets', [...$base, 'handle' => 'mx'])->assertJsonValidationErrors('handle');        // taken
        $this->as()->postJson('/api/admin/markets', [...$base, 'handle' => 'EU-1'])->assertJsonValidationErrors('handle');      // letters only
        $this->as()->postJson('/api/admin/markets', [...$base, 'handle' => 'eu', 'countries' => ['MX']])->assertJsonValidationErrors('countries');   // MX is Mexico's
        // No rate for THB yet: can't go live without a manual rate
        $this->as()->postJson('/api/admin/markets', [...$base, 'handle' => 'th', 'countries' => ['TH'], 'currency' => 'THB'])->assertJsonValidationErrors('exchange_rate');
        $this->as()->postJson('/api/admin/markets', [...$base, 'handle' => 'th', 'countries' => ['TH'], 'currency' => 'THB', 'exchange_rate' => 35])->assertCreated();
    }

    public function test_market_storefront_shows_converted_prices_in_its_languages(): void
    {
        $this->mexico();

        // $40 × 20 = MX$800.00 → ".99" ending
        // Mexican shoppers write pesos as "$800.99" (es-MX); in English it's "MX$800.99"
        $this->front()->get(self::HOST.'/es-mx/products/rose-ring')->assertOk()->assertSee('$800.99')->assertDontSee('MX$800.99')
            ->assertSee('href="/es-mx/cart"', false)          // internal links stay in the market
            ->assertSee('hreflang="es-MX"', false);
        $this->front()->get(self::HOST.'/en-mx/products/rose-ring')->assertOk()->assertSee('MX$800.99');
        $this->front()->get(self::HOST.'/products/rose-ring')->assertOk()->assertSee('$40.00')->assertDontSee('MX$');
        // Grids that load only some variant columns (search) are converted too
        $this->front()->get(self::HOST.'/es-mx/search?q=rose')->assertOk()->assertSee('$800.99')->assertDontSee('$40.00');

        $this->front()->get(self::HOST.'/fr-mx/products/rose-ring')->assertNotFound();   // not a language of the market
        $this->front()->get(self::HOST.'/es-zz/products/rose-ring')->assertNotFound();   // no such market

        // Disabled markets are not served
        Market::withoutGlobalScope('store')->update(['enabled' => false]);
        $this->front()->get(self::HOST.'/es-mx/products/rose-ring')->assertNotFound();
    }

    public function test_fixed_prices_and_product_range_per_market(): void
    {
        $m = $this->mexico();
        $payload = fn (bool $sold, $price) => ['markets' => [['id' => $m['id'], 'sold' => $sold, 'prices' => [['variant_id' => $this->variantId, 'price' => $price, 'compare_at_price' => 999]]]]];

        $res = $this->as()->putJson("/api/admin/products/{$this->productId}/markets", $payload(true, 699))->assertOk();
        $this->assertSame('699.00', $res->json('data.0.variants.0.price'));
        $this->assertSame('800.99', $res->json('data.0.variants.0.auto_price'));
        $this->front()->get(self::HOST.'/es-mx/products/rose-ring')->assertSee('$699.00')->assertSee('$999.00');

        // Not sold in Mexico: gone from the Mexico site only
        $this->as()->putJson("/api/admin/products/{$this->productId}/markets", $payload(false, null))->assertOk();
        $this->front()->get(self::HOST.'/es-mx/products/rose-ring')->assertNotFound();
        $this->front()->get(self::HOST.'/es-mx/collections/all')->assertOk()->assertDontSee('Rose Ring');
        $this->front()->get(self::HOST.'/products/rose-ring')->assertOk();
        $this->front()->post(self::HOST.'/es-mx/cart/add', ['variant_id' => $this->variantId, 'quantity' => 1])->assertNotFound();
        $this->assertStringNotContainsString('/es-mx/products/rose-ring', $this->front()->get(self::HOST.'/sitemap.xml')->getContent());
    }

    public function test_price_sorting_uses_market_prices(): void
    {
        $m = $this->mexico();
        $this->as()->postJson('/api/admin/products', ['title' => 'Gold Chain', 'price' => 30, 'inventory' => 5, 'status' => 'active'])->assertCreated();

        // Default site: $30 chain before the $40 ring
        $html = $this->front()->get(self::HOST.'/collections/all?sort=price_asc')->assertOk()->getContent();
        $this->assertLessThan(strpos($html, 'Rose Ring'), strpos($html, 'Gold Chain'));

        // Mexico: the ring has a fixed MX$100 (cheaper than the chain's converted MX$600.99)
        $this->as()->putJson("/api/admin/products/{$this->productId}/markets", ['markets' => [['id' => $m['id'], 'sold' => true, 'prices' => [['variant_id' => $this->variantId, 'price' => 100, 'compare_at_price' => null]]]]])->assertOk();
        $html = $this->front()->get(self::HOST.'/es-mx/collections/all?sort=price_asc')->assertOk()->getContent();
        $this->assertLessThan(strpos($html, 'Gold Chain'), strpos($html, 'Rose Ring'));
        $html = $this->front()->get(self::HOST.'/es-mx/collections/all?sort=price_desc')->assertOk()->getContent();
        $this->assertLessThan(strpos($html, 'Rose Ring'), strpos($html, 'Gold Chain'));
    }

    public function test_checkout_in_a_market_charges_the_market_currency(): void
    {
        $this->mexico();
        $res = $this->front()->post(self::HOST.'/es-mx/cart/add', ['variant_id' => $this->variantId, 'quantity' => 2]);
        $res->assertRedirect('/es-mx/cart');
        $cart = $res->getCookie('lumee_cart', false)->getValue();
        $this->front($cart)->get(self::HOST.'/es-mx/checkout')->assertOk()->assertSee('$1,601.98')
            ->assertSee('new Intl.NumberFormat("es-MX"', false);   // live totals use the same format

        $shipping = (int) \Illuminate\Support\Facades\DB::table('shipping_rates')->where('price', '>', 0)->value('price');   // as saved (store currency)
        $this->checkout('/es-mx', $cart)->assertRedirect();

        $order = Order::withoutGlobalScope('store')->firstOrFail();
        $this->assertSame('MXN', $order->currency);
        $this->assertSame(160198, $order->subtotal);
        $this->assertSame($shipping * 20, $order->shipping_total);   // shipping rates are converted too
        $this->assertSame(160198 + $shipping * 20, $order->total);
        $this->assertSame(20.0, $order->exchange_rate);
        $this->assertNotNull($order->market_id);
        $this->assertSame('paid', $order->financial_status);
        // Lifetime spend and reports stay in the store currency
        $this->assertDatabaseHas('customers', ['email' => 'buyer@example.com', 'total_spent' => (int) round($order->total / 20)]);
        $this->assertSame('USD', \Illuminate\Support\Facades\DB::table('stores')->where('id', $this->a['store_id'])->value('currency'));   // never saved as MXN
    }

    public function test_cash_on_delivery_per_market(): void
    {
        $m = $this->mexico();
        $res = $this->front()->post(self::HOST.'/es-mx/cart/add', ['variant_id' => $this->variantId, 'quantity' => 1]);
        $cart = $res->getCookie('lumee_cart', false)->getValue();

        // Off by default: not offered, and can't be forced
        $this->front($cart)->get(self::HOST.'/es-mx/checkout')->assertOk()->assertDontSee('value="cod"', false);
        $this->checkout('/es-mx', $cart, ['payment_gateway' => 'cod'])->assertSessionHasErrors('payment_gateway');

        // On for Mexico: MX$50 fee, up to MX$2,000
        $this->as()->putJson("/api/admin/markets/{$m['id']}/localization", ['theme' => [], 'duties' => ['mode' => 'none'], 'cod' => ['enabled' => true, 'fee' => 50, 'max' => 2000]])
            ->assertOk()->assertJsonPath('data.settings.cod', ['enabled' => true, 'fee' => '50.00', 'max' => '2000.00']);
        $this->front($cart)->get(self::HOST.'/es-mx/checkout')->assertOk()->assertSee('value="cod"', false);
        $this->front($cart)->get(self::HOST.'/checkout')->assertDontSee('value="cod"', false);   // default site: no COD

        // Needs a phone and a local address
        $this->checkout('/es-mx', $cart, ['payment_gateway' => 'cod'])->assertSessionHasErrors('payment_gateway');
        $this->checkout('/es-mx', $cart, ['payment_gateway' => 'cod', 'phone' => '+52 55 1234 5678', 'country' => 'US', 'province' => 'CA'])->assertSessionHasErrors();
        $this->assertSame(0, Order::withoutGlobalScope('store')->count());

        $this->checkout('/es-mx', $cart, ['payment_gateway' => 'cod', 'phone' => '+52 55 1234 5678'])->assertRedirect()->assertSessionHasNoErrors();
        $order = Order::withoutGlobalScope('store')->firstOrFail();
        $this->assertSame('cod', $order->payment_gateway);
        $this->assertSame('pending', $order->financial_status);
        $this->assertSame(5000, $order->payment_fee);
        $this->assertSame($order->subtotal + $order->shipping_total + 5000, $order->total);
        $this->front()->get(self::HOST.'/es-mx/orders/'.$order->token)->assertOk()->assertSee('50.00');

        // Over the ceiling: 3 × MX$800.99 > MX$2,000
        $res = $this->front()->post(self::HOST.'/es-mx/cart/add', ['variant_id' => $this->variantId, 'quantity' => 3]);
        $this->checkout('/es-mx', $res->getCookie('lumee_cart', false)->getValue(), ['payment_gateway' => 'cod', 'phone' => '555'])->assertSessionHasErrors('payment_gateway');
    }

    public function test_card_installments_in_mexico(): void
    {
        $m = $this->mexico();
        $this->front()->get(self::HOST.'/es-mx/products/rose-ring')->assertOk()->assertDontSee('id="installments"', false);

        // 12 months from MX$500: MX$800.99 / 12 = MX$66.75
        $this->as()->putJson("/api/admin/markets/{$m['id']}/localization", ['theme' => [], 'duties' => ['mode' => 'none'], 'installments' => ['enabled' => true, 'months' => 12, 'min' => 500, 'interest_free' => true]])
            ->assertOk()->assertJsonPath('data.settings.installments.available', true)->assertJsonPath('data.settings.installments.min', '500.00');
        $this->front()->get(self::HOST.'/es-mx/products/rose-ring')->assertOk()->assertSee('id="installments"', false)->assertSee('$66.75');
        $this->front()->get(self::HOST.'/products/rose-ring')->assertOk()->assertDontSee('id="installments"', false);   // USD site: no
        $this->as()->putJson("/api/admin/markets/{$m['id']}/localization", ['theme' => [], 'duties' => ['mode' => 'none'], 'installments' => ['enabled' => true, 'months' => 7]])
            ->assertJsonValidationErrors('installments.months');

        // Stripe Checkout gets installments switched on for the order
        config(['services.stripe.secret' => 'sk_test_123']);
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1'])]);
        $res = $this->front()->post(self::HOST.'/es-mx/cart/add', ['variant_id' => $this->variantId, 'quantity' => 1]);
        $this->checkout('/es-mx', $res->getCookie('lumee_cart', false)->getValue(), ['payment_gateway' => 'stripe'])->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_1');
        $order = Order::withoutGlobalScope('store')->firstOrFail();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/checkout/sessions')
            && $r['payment_method_options[card][installments][enabled]'] === 'true'
            && $r['line_items[0][price_data][currency]'] === 'mxn');
        // The lines Stripe charges always add up to the order total
        Http::assertSent(function ($r) use ($order) {
            $sum = 0;
            for ($n = 0; isset($r["line_items[$n][quantity]"]); $n++) {
                $sum += $r["line_items[$n][quantity]"] * $r["line_items[$n][price_data][unit_amount]"];
            }

            return str_ends_with($r->url(), '/checkout/sessions') && $sum === $order->total;
        });
    }

    public function test_store_currency_amounts_are_converted_inside_a_market(): void
    {
        $this->mexico();
        // $10 off code with a $50 minimum → MX$200 off, minimum MX$1,000
        $this->as()->postJson('/api/admin/discounts', ['code' => 'TEN', 'type' => 'fixed', 'value' => 10, 'min_subtotal' => 50, 'active' => true])->assertCreated();
        $res = $this->front()->post(self::HOST.'/es-mx/cart/add', ['variant_id' => $this->variantId, 'quantity' => 1]);
        $cart = $res->getCookie('lumee_cart', false)->getValue();
        // MX$800.99 is under the MX$1,000 minimum
        $this->front($cart)->post(self::HOST.'/es-mx/cart/discount', ['code' => 'TEN'])->assertSessionHasErrors('discount_code');
        $this->front($cart)->post(self::HOST.'/es-mx/cart/items/'.\App\Models\CartItem::query()->withoutGlobalScopes()->value('id'), ['quantity' => 2]);
        $this->front($cart)->post(self::HOST.'/es-mx/cart/discount', ['code' => 'TEN'])->assertSessionHasNoErrors();

        $this->checkout('/es-mx', $cart)->assertRedirect();
        $order = Order::withoutGlobalScope('store')->firstOrFail();
        $this->assertSame(20000, $order->discount_total);
    }

    public function test_seo_and_suggestion(): void
    {
        $this->mexico();

        $home = $this->front()->get(self::HOST.'/products/rose-ring')->assertOk();
        $home->assertSee('hreflang="es-MX" href="'.self::HOST.'/es-mx/products/rose-ring"', false);
        $home->assertSee('hreflang="x-default"', false);
        $home->assertSee('Country/region');
        $home->assertDontSee('mk-suggest');   // no country known

        $this->front()->withHeader('CF-IPCountry', 'MX')->get(self::HOST.'/')->assertSee('Visit our Mexico store')->assertSee(self::HOST.'/es-mx', false);
        $this->front()->withHeader('CF-IPCountry', 'US')->get(self::HOST.'/')->assertDontSee('mk-suggest');
        $this->front()->withHeader('CF-IPCountry', 'MX')->withUnencryptedCookie('lumee_market_choice', 'stay')->get(self::HOST.'/')->assertDontSee('mk-suggest');
        $this->front()->withHeader('CF-IPCountry', 'MX')->get(self::HOST.'/es-mx')->assertOk()->assertDontSee('mk-suggest');   // already there

        $sitemap = $this->front()->get(self::HOST.'/sitemap.xml')->getContent();
        $this->assertStringContainsString(self::HOST.'/es-mx/products/rose-ring', $sitemap);
        $this->assertStringContainsString(self::HOST.'/en-mx/products/rose-ring', $sitemap);
    }

    public function test_brazil_market_in_portuguese(): void
    {
        $this->as()->postJson('/api/admin/markets', [
            'name' => 'Brasil', 'handle' => 'br', 'countries' => ['BR'], 'currency' => 'BRL',
            'default_locale' => 'pt', 'locales' => [], 'price_adjustment' => 0, 'rounding' => '0.90', 'enabled' => true,
        ])->assertCreated();

        // $40 × 5 = R$200.00 → ".90" ending, written the Brazilian way; storefront text in Portuguese
        $page = $this->front()->get(self::HOST.'/pt-br/products/rose-ring')->assertOk();
        $page->assertSee('<html lang="pt" dir="ltr">', false)->assertSee("R\$\u{00A0}200,90")->assertSee('Adicionar à sacola')->assertSee('hreflang="pt-BR"', false);

        // Validation messages for shoppers are Portuguese too
        $res = $this->front()->post(self::HOST.'/pt-br/cart/add', ['variant_id' => $this->variantId, 'quantity' => 1]);
        $this->front($res->getCookie('lumee_cart', false)->getValue())->from(self::HOST.'/pt-br/checkout')
            ->post(self::HOST.'/pt-br/checkout', ['email' => ''])->assertSessionHasErrors(['email' => 'O campo e-mail é obrigatório.']);
    }

    public function test_amounts_use_each_market_s_number_format(): void
    {
        $this->as()->postJson('/api/admin/markets', [
            'name' => 'Europe', 'handle' => 'eu', 'countries' => ['DE', 'FR'], 'currency' => 'EUR',
            'default_locale' => 'de', 'locales' => ['en'], 'price_adjustment' => 0, 'rounding' => 'none', 'enabled' => true,
        ])->assertCreated();

        // $40 × 0.9 = €36: German "36,00 €", English "€36.00"; the default site keeps "$40.00"
        $this->front()->get(self::HOST.'/de-eu/products/rose-ring')->assertSee("36,00\u{00A0}€");
        $this->front()->get(self::HOST.'/en-eu/products/rose-ring')->assertSee('€36.00');
        $this->front()->get(self::HOST.'/products/rose-ring')->assertSee('$40.00')->assertSee('new Intl.NumberFormat("en-US"', false);
    }

    public function test_markets_are_per_store(): void
    {
        $m = $this->mexico();
        $b = $this->postJson('/api/admin/auth/register', ['name' => 'B', 'email' => 'b@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop B'])->json();
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$b['token'], 'X-Store-Id' => (string) $b['stores'][0]['id']])
            ->putJson("/api/admin/markets/{$m['id']}", ['name' => 'Hijack'])->assertNotFound();
        $this->front()->get('http://shop-b.lumee.localhost/es-mx')->assertNotFound();
    }
}
