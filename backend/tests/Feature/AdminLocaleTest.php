<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLocaleTest extends TestCase
{
    use RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => 'a@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop A'])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
    }

    private function as(?string $locale): static
    {
        $this->app['auth']->forgetGuards();
        $headers = ['Authorization' => 'Bearer '.$this->a['token'], 'X-Store-Id' => (string) $this->a['store_id'], 'Accept' => 'application/json'];

        return $this->withHeaders($locale ? $headers + ['X-Admin-Locale' => $locale] : $headers);
    }

    public function test_api_speaks_the_admin_language(): void
    {
        // Default: Chinese
        $this->as(null)->postJson('/api/admin/domains', ['host' => 'not a domain'])
            ->assertStatus(422)->assertJsonPath('errors.host.0', '域名格式不正确，例如 shop.yourbrand.com');
        $this->as(null)->getJson('/api/admin/billing')->assertJsonPath('plans.1.name', '基础版');

        // English admin: messages, plan names, theme editor schema, permission labels
        $this->as('en')->postJson('/api/admin/domains', ['host' => 'not a domain'])
            ->assertStatus(422)->assertJsonPath('errors.host.0', 'Invalid domain, e.g. shop.yourbrand.com');
        $this->as('en')->getJson('/api/admin/billing')
            ->assertJsonPath('plans.1.name', 'Basic')->assertJsonPath('plans.1.features.1', 'Connect your own domain');
        $theme = $this->as('en')->getJson('/api/admin/theme')->assertOk();
        $this->assertSame('Colors', $theme->json('schema.settings.colors.label'));
        $this->assertSame('Hero banner', $theme->json('schema.sections.hero.label'));
        $this->assertSame('Medium', $theme->json('schema.sections.hero.fields.height.options.medium'));
        $this->as('en')->getJson('/api/admin/staff')->assertJsonPath('permissions.0.label', 'Orders');
        $this->as('en')->getJson('/api/admin/menus')->assertJsonPath('data.0.title', 'Main menu')->assertJsonPath('types.url', 'Custom link');

        // Unknown header value → Chinese
        $this->as('xx')->getJson('/api/admin/billing')->assertJsonPath('plans.1.name', '基础版');
    }

    public function test_merchant_emails_follow_the_owners_admin_language(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        config(['lumee.payments.test_gateway' => true]);
        $this->as('en')->getJson('/api/admin/me')->assertOk();   // owner uses the English admin
        $this->assertSame('en', \App\Models\User::where('email', 'a@example.com')->value('locale'));

        $variant = $this->as('en')->postJson('/api/admin/products', ['title' => 'Ring', 'price' => 10, 'inventory' => 5, 'status' => 'active'])->json('data.variants.0.id');
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $cart = $this->post('http://shop-a.lumee.localhost/cart/add', ['variant_id' => $variant, 'quantity' => 1])->getCookie('lumee_cart', false)->getValue();
        $rate = \App\Models\ShippingRate::withoutGlobalScope('store')->first();
        $this->withUnencryptedCookie('lumee_cart', $cart)->post('http://shop-a.lumee.localhost/checkout', [
            'email' => 'b@example.com', 'first_name' => 'A', 'last_name' => 'B', 'address1' => '1 St', 'city' => 'X', 'zip' => '1',
            'country' => 'US', 'shipping_rate_id' => $rate->id, 'payment_gateway' => 'test',
        ])->assertRedirect();

        \Illuminate\Support\Facades\Mail::assertSent(\App\Notifications\OrderMail::class, fn ($m) => $m->type === 'merchant_new_order'
            && $m->locale === 'en' && str_contains($m->render(), 'Fulfill in admin'));
    }
}
