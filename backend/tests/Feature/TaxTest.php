<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TaxRate;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TaxTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private array $a;

    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.payments.test_gateway' => true]);
        Mail::fake();

        $res = $this->postJson('/api/admin/auth/register', [
            'name' => 'M', 'email' => 'owner@shop-a.test', 'password' => 'secret-pass', 'store_name' => 'Shop A',
        ])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];

        $this->variantId = $this->as()->postJson('/api/admin/products', [
            'title' => 'Mug', 'price' => 30, 'inventory' => 50, 'status' => 'active',
        ])->assertCreated()->json('data.variants.0.id');
    }

    private function as(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->a['token'], 'X-Store-Id' => (string) $this->a['store_id'], 'Accept' => 'application/json']);
    }

    private function taxes(bool $included, bool $shipping, array $rates): void
    {
        $this->as()->putJson('/api/admin/settings/taxes', compact('included', 'shipping', 'rates'))->assertOk();
    }

    private function cart(array $items): string
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $cart = null;
        foreach ($items as [$variant, $qty]) {
            $req = $cart ? $this->withUnencryptedCookie('lumee_cart', $cart) : $this;
            $cart = $req->post(self::HOST.'/cart/add', ['variant_id' => $variant, 'quantity' => $qty])->getCookie('lumee_cart', false)?->getValue() ?? $cart;
        }

        return $cart;
    }

    /** Places an order with the cheapest rate; returns the order. */
    private function order(string $cart, string $country, ?string $province = null): Order
    {
        $rates = $this->withCredentials()->withUnencryptedCookie('lumee_cart', $cart)
            ->getJson(self::HOST.'/checkout/shipping-rates?country='.$country.'&province='.$province)->assertOk();
        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/checkout', [
            'email' => 'b@example.com', 'first_name' => 'A', 'last_name' => 'B', 'address1' => '1 St', 'city' => 'X', 'zip' => '1',
            'country' => $country, 'province' => $province, 'payment_gateway' => 'test', 'shipping_rate_id' => $rates->json('rates.0.id'),
        ])->assertRedirect()->assertSessionHasNoErrors();

        return Order::withoutGlobalScope('store')->latest('id')->firstOrFail();
    }

    public function test_no_rates_means_no_tax(): void
    {
        $o = $this->order($this->cart([[$this->variantId, 2]]), 'DE');
        $this->assertSame(0, $o->tax_total);
        $this->assertSame(6000 + $o->shipping_total, $o->total);
    }

    public function test_inclusive_vat_is_extracted_and_total_unchanged(): void
    {
        $this->taxes(true, false, [['country' => 'DE', 'region' => null, 'name' => 'MwSt', 'rate' => 19]]);
        $o = $this->order($this->cart([[$this->variantId, 2]]), 'DE');

        $this->assertTrue($o->taxes_included);
        $this->assertSame((int) round(6000 - 6000 / 1.19), $o->tax_total);   // 958
        $this->assertSame(6000 + $o->shipping_total, $o->total);
        $this->assertSame('MwSt', $o->tax_lines[0]['name']);
    }

    public function test_exclusive_tax_added_and_region_overrides_country(): void
    {
        $this->taxes(false, false, [
            ['country' => 'US', 'region' => null, 'name' => 'Sales tax', 'rate' => 5],
            ['country' => 'US', 'region' => 'ca', 'name' => 'CA sales tax', 'rate' => 7.25],
        ]);
        $cart = $this->cart([[$this->variantId, 2]]);

        $ca = $this->order($cart, 'US', 'CA');
        $this->assertSame(435, $ca->tax_total);
        $this->assertSame(6000 + $ca->shipping_total + 435, $ca->total);
        $this->assertSame('CA sales tax', $ca->tax_lines[0]['name']);

        $ny = $this->order($this->cart([[$this->variantId, 2]]), 'US', 'NY');
        $this->assertSame(300, $ny->tax_total);

        $gb = $this->order($this->cart([[$this->variantId, 2]]), 'GB');
        $this->assertSame(0, $gb->tax_total);
    }

    public function test_non_taxable_products_and_shipping_tax(): void
    {
        $gift = $this->as()->postJson('/api/admin/products', [
            'title' => 'Gift card', 'price' => 50, 'inventory' => 50, 'status' => 'active', 'taxable' => false,
        ])->assertCreated();
        $this->assertFalse($gift->json('data.taxable'));

        $this->taxes(false, true, [['country' => 'GB', 'region' => null, 'name' => 'VAT', 'rate' => 20]]);
        $o = $this->order($this->cart([[$this->variantId, 1], [$gift->json('data.variants.0.id'), 1]]), 'GB');

        $this->assertGreaterThan(0, $o->shipping_total);
        $this->assertSame((int) round((3000 + $o->shipping_total) * 0.2), $o->tax_total);   // gift card not taxed, shipping taxed
        $this->assertSame(8000 + $o->shipping_total + $o->tax_total, $o->total);
    }

    public function test_rates_endpoint_reports_tax_for_checkout_page(): void
    {
        $this->taxes(false, false, [['country' => 'FR', 'region' => null, 'name' => 'TVA', 'rate' => 20]]);
        $cart = $this->cart([[$this->variantId, 1]]);
        $tax = $this->withCredentials()->withUnencryptedCookie('lumee_cart', $cart)
            ->getJson(self::HOST.'/checkout/shipping-rates?country=FR')->assertOk()->json('tax');

        $this->assertSame('TVA', $tax['name']);
        $this->assertEquals(20, $tax['rate']);
        $this->assertFalse($tax['included']);
    }

    public function test_settings_api_validates_and_replaces(): void
    {
        $this->as()->getJson('/api/admin/settings/taxes')->assertOk()
            ->assertJsonPath('settings', ['included' => false, 'shipping' => false, 'stripe_tax' => false])
            ->assertJsonPath('rates', [])
            ->assertJsonPath('presets.0.key', 'eu_vat')->assertJsonPath('presets.0.countries', 27);

        $row = ['country' => 'DE', 'region' => null, 'name' => 'MwSt', 'rate' => 19];
        $this->as()->putJson('/api/admin/settings/taxes', ['included' => true, 'shipping' => false, 'rates' => [$row, $row]])
            ->assertStatus(422)->assertJsonValidationErrors('rates.1.country');
        $this->as()->putJson('/api/admin/settings/taxes', ['included' => true, 'shipping' => false, 'rates' => [[...$row, 'rate' => 120]]])
            ->assertStatus(422)->assertJsonValidationErrors('rates.0.rate');
        $this->as()->putJson('/api/admin/settings/taxes', ['included' => true, 'shipping' => false, 'rates' => [[...$row, 'country' => 'XX']]])
            ->assertStatus(422);

        $this->taxes(true, true, [$row, [...$row, 'country' => 'FR', 'name' => 'TVA', 'rate' => 20]]);
        $this->assertSame(2, TaxRate::withoutGlobalScope('store')->count());
        $this->taxes(true, true, [$row]);
        $this->assertSame(1, TaxRate::withoutGlobalScope('store')->count());
        $this->as()->getJson('/api/admin/settings/taxes')->assertJsonPath('settings', ['included' => true, 'shipping' => true, 'stripe_tax' => false]);
    }
}
