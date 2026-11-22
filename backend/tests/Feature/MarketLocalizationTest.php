<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\ShippingRate;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Deeper market localization: presets, theme texts, duties, tax IDs, sizes, calendar, payments. */
class MarketLocalizationTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.payments.test_gateway' => true, 'services.stripe.secret' => null, 'lumee.storefront_scheme' => 'http', 'lumee.storefront_port' => null]);
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => 'a@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop A'])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
        Http::fake(['open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['USD' => 1, 'MXN' => 20, 'BRL' => 5, 'EUR' => 0.9]])]);
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

    private function market(string $name, string $handle, string $country, string $currency, string $locale): int
    {
        return $this->as()->postJson('/api/admin/markets', ['name' => $name, 'handle' => $handle, 'countries' => [$country], 'currency' => $currency,
            'default_locale' => $locale, 'locales' => [], 'price_adjustment' => 0, 'rounding' => 'none', 'enabled' => true])->assertCreated()->json('data.id');
    }

    private function product(array $extra = []): int
    {
        return $this->as()->postJson('/api/admin/products', ['title' => 'Rose Ring', 'price' => 40, 'inventory' => 20, 'status' => 'active', ...$extra])->json('data.id');
    }

    private function addToCart(string $prefix, int $productId): string
    {
        $v = ProductVariant::withoutGlobalScope('store')->where('product_id', $productId)->value('id');

        return $this->front()->post(self::HOST.$prefix.'/cart/add', ['variant_id' => $v, 'quantity' => 1])->getCookie('lumee_cart', false)->getValue();
    }

    private function checkout(string $prefix, string $cart, array $extra): \Illuminate\Testing\TestResponse
    {
        return $this->front($cart)->from(self::HOST.$prefix.'/checkout')->post(self::HOST.$prefix.'/checkout', [
            'email' => 'buyer@example.com', 'first_name' => 'Ana', 'last_name' => 'Silva', 'address1' => 'Rua A 1', 'city' => 'São Paulo',
            'zip' => '01000-000', 'shipping_rate_id' => ShippingRate::withoutGlobalScope('store')->value('id'), 'payment_gateway' => 'test', ...$extra,
        ]);
    }

    public function test_regional_preset_and_market_theme_texts(): void
    {
        $this->product();
        $br = $this->market('Brasil', 'br', 'BR', 'BRL', 'pt');
        $m = $this->as()->postJson("/api/admin/markets/{$br}/preset", ['preset' => 'latam'])->assertOk()->json('data');
        $this->assertSame('0.90', $m['rounding']);
        $this->assertSame('on_delivery', $m['settings']['duties']['mode']);
        $this->assertContains('pix', collect($m['payments'])->pluck('key')->all());
        $this->assertFalse(collect($m['payments'])->firstWhere('key', 'pix')['enabled']);
        // The announcement in Portuguese goes straight onto the Brazil site's live design
        $this->front()->get(self::HOST.'/pt-br')->assertOk()->assertSee('Frete para todo o Brasil');

        // Own hero heading and colour for the Brazil site, in the theme editor
        $d = $this->as()->getJson("/api/admin/theme?market={$br}")->assertOk()->json();
        $this->assertSame('Brasil', $d['market']['name']);
        $this->assertTrue(collect($d['markets'])->firstWhere('id', $br)['customized']);
        $config = $d['config'];
        $config['settings']['colors']['primary'] = '#0A7D4B';
        $config['sections'][0]['settings']['heading'] = 'Joias para o dia a dia';
        $this->as()->putJson('/api/admin/theme', [...$config, 'id' => $d['id'], 'market' => $br])->assertOk();
        $this->as()->postJson('/api/admin/theme/publish', ['id' => $d['id'], 'market' => $br])->assertOk();
        $home = $this->front()->get(self::HOST.'/pt-br')->assertOk();
        $home->assertSee('Frete para todo o Brasil')->assertSee('Joias para o dia a dia')->assertSee('#0A7D4B', false);
        $this->front()->get(self::HOST.'/')->assertOk()->assertDontSee('Frete para todo o Brasil')->assertDontSee('Joias para o dia a dia');
    }

    public function test_duties_charged_at_checkout_and_tax_id_for_brazil(): void
    {
        $pid = $this->product();
        $br = $this->market('Brasil', 'br', 'BR', 'BRL', 'pt');
        // Goods above R$ 100 pay 20% duty at checkout
        $this->as()->putJson("/api/admin/markets/{$br}/localization", ['duties' => ['mode' => 'checkout', 'rate' => 20, 'threshold' => 100]])->assertOk();
        $cart = $this->addToCart('/pt-br', $pid);   // $40 × 5 = R$ 200
        $this->front($cart)->get(self::HOST.'/pt-br/checkout?country=BR')->assertOk()->assertSee('Impostos de importação')->assertSee("R\$\u{00A0}40,00", false);

        $this->checkout('/pt-br', $cart, ['country' => 'BR'])->assertSessionHasErrors('tax_id');
        $this->checkout('/pt-br', $cart, ['country' => 'BR', 'tax_id' => '111.111.111-11'])->assertSessionHasErrors('tax_id');   // fails the check digits
        $this->checkout('/pt-br', $cart, ['country' => 'BR', 'tax_id' => '529.982.247-25'])->assertRedirect();

        $order = Order::withoutGlobalScope('store')->firstOrFail();
        $this->assertSame(4000, $order->duty_total);
        $this->assertSame(20000 + 4000 + $order->shipping_total, $order->total);
        $this->assertSame('529.982.247-25', $order->shipping_address['tax_id']);
        $this->front()->get(self::HOST."/pt-br/orders/{$order->token}")->assertSee('Impostos de importação');   // in the shopper's language
        $this->as()->getJson("/api/admin/orders/{$order->id}")->assertJsonPath('data.duty_total', '40.00');

        // Cheap enough → no duty; other countries don't ask for a tax ID
        $pid2 = $this->product(['title' => 'Pin', 'price' => 10]);   // R$ 50
        $cart2 = $this->addToCart('/pt-br', $pid2);
        $this->checkout('/pt-br', $cart2, ['country' => 'US'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(0, Order::withoutGlobalScope('store')->latest('id')->first()->duty_total);
    }

    public function test_duties_included_or_on_delivery_are_explained(): void
    {
        $pid = $this->product();
        $mx = $this->market('Mexico', 'mx', 'MX', 'MXN', 'es');
        $this->as()->putJson("/api/admin/markets/{$mx}/localization", ['duties' => ['mode' => 'on_delivery']])->assertOk();
        $cart = $this->addToCart('/es-mx', $pid);
        $this->front($cart)->get(self::HOST.'/es-mx/checkout')->assertSee('al entregar');   // "may be collected by the carrier on delivery", in Spanish
        $this->as()->putJson("/api/admin/markets/{$mx}/localization", ['duties' => ['mode' => 'included']])->assertOk();
        $this->front($cart)->get(self::HOST.'/es-mx/checkout')->assertSee('Aranceles incluidos');
    }

    public function test_size_guide_in_the_shoppers_system(): void
    {
        $this->market('Europe', 'eu', 'DE', 'EUR', 'en');
        $this->as()->postJson('/api/admin/products', ['title' => 'Ballet Flat', 'status' => 'active', 'size_chart' => 'shoes_women',
            'options' => [['name' => 'Size', 'values' => ['7', '8']]],
            'variants' => [['options' => ['Size' => '7'], 'price' => 50, 'inventory' => 5], ['options' => ['Size' => '8'], 'price' => 50, 'inventory' => 5]]])->assertCreated();

        $eu = $this->front()->get(self::HOST.'/en-eu/products/ballet-flat')->assertOk();
        $eu->assertSee('EU 37.5')->assertSee('Size guide')->assertSee('<th>EU</th>', false);
        $this->assertLessThan(strpos($eu->getContent(), '<th>UK</th>'), strpos($eu->getContent(), '<th>EU</th>'));   // the shopper's system first
        $this->front()->get(self::HOST.'/products/ballet-flat')->assertOk()->assertSee('Size guide')->assertDontSee('EU 37.5');   // no local label on the default site
        $this->as()->putJson('/api/admin/products/'.\App\Models\Product::withoutGlobalScope('store')->where('title', 'Ballet Flat')->value('id'), ['size_chart' => 'hats'])->assertJsonValidationErrors('size_chart');
    }

    public function test_shopping_calendar_per_market(): void
    {
        Carbon::setTestNow('2026-10-20');
        $mx = $this->market('Mexico', 'mx', 'MX', 'MXN', 'es');
        $events = collect($this->as()->getJson("/api/admin/markets/{$mx}/calendar")->assertOk()->json('data'));
        $buenFin = $events->firstWhere('name', 'Buen Fin（墨西哥）');
        $this->assertSame('2026-11-13', $buenFin['start']);
        $this->assertTrue($buenFin['preparing']);   // 6 weeks before
        $this->assertNull($events->firstWhere('name', '双十一（11.11）'));
        $this->assertNotNull(collect($this->as()->getJson('/api/admin/markets/0/calendar')->json('data'))->firstWhere('name', '黑色星期五'));
        Carbon::setTestNow();
    }

    public function test_local_payment_methods_follow_the_order_currency(): void
    {
        $mx = $this->market('Mexico', 'mx', 'MX', 'MXN', 'es');
        $this->as()->getJson('/api/admin/settings/payments')->assertOk();
        $methods = collect($this->as()->getJson('/api/admin/settings/payments')->json('stripe_methods'))->keyBy('key');
        $this->assertTrue($methods['oxxo']['supported']);   // usable thanks to the Mexico market
        $this->assertFalse($methods['promptpay']['supported']);
        $settings = \App\Payments\PaymentSettings::for(\App\Models\Store::find($this->a['store_id']));
        $this->assertSame([], $settings->stripeMethods('MXN'));   // nothing turned on yet
        $payments = collect($this->as()->getJson('/api/admin/markets')->json('data'))->firstWhere('id', $mx)['payments'];
        $this->assertContains('oxxo', array_column($payments, 'key'));   // suggested for the Mexico market
    }
}
