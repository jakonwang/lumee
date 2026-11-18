<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\Order;
use App\Models\ShippingRate;
use App\Notifications\OrderMail;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StorefrontI18nTest extends TestCase
{
    use RefreshDatabase;

    private const A = 'http://shop-a.lumee.localhost';

    private array $a;

    private array $product;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.payments.test_gateway' => true]);
        Mail::fake();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $res = $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => 'a@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop A'])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
        $this->product = $this->as()->postJson('/api/admin/products', [
            'title' => 'Silk Scarf', 'description' => 'Soft and light.', 'status' => 'active',
            'options' => [['name' => 'Color', 'values' => ['White', 'Blue']]],
            'variants' => [['options' => ['Color' => 'White'], 'price' => 40, 'inventory' => 5], ['options' => ['Color' => 'Blue'], 'price' => 40, 'inventory' => 5]],
        ])->assertCreated()->json('data');

        $this->as()->putJson('/api/admin/settings/languages', ['default' => 'en', 'published' => ['fr', 'ja']])->assertOk();
    }

    private function as(?array $m = null): static
    {
        $m ??= $this->a;
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$m['token'], 'X-Store-Id' => (string) $m['store_id'], 'Accept' => 'application/json']);
    }

    private function front(): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this;
    }

    private function translate(string $type, int $id, array $fields, string $locale = 'fr')
    {
        return $this->as()->putJson("/api/admin/translations/{$type}/{$id}", ['locale' => $locale, 'fields' => $fields]);
    }

    public function test_language_settings_and_urls(): void
    {
        $this->as()->getJson('/api/admin/settings/languages')->assertOk()
            ->assertJsonPath('default', 'en')->assertJsonPath('published', ['fr', 'ja'])
            ->assertJsonCount(6, 'languages');

        $home = $this->front()->get(self::A.'/fr')->assertOk();
        $home->assertSee('<html lang="fr">', false)
            ->assertSee('href="/fr/collections/all"', false)                  // internal links keep the language
            ->assertSee('hreflang="ja"', false)->assertSee('hreflang="x-default"', false)
            ->assertSee('>Français</a>', false)->assertSee('Rechercher');
        $this->assertStringNotContainsString('href="/fr/storage', $home->getContent());

        $this->front()->get(self::A.'/de')->assertNotFound();                 // not published
        $this->front()->get(self::A.'/en/collections/all')->assertRedirect('/collections/all');   // default language has no prefix
        $this->front()->get(self::A.'/collections/all')->assertOk()->assertSee('<html lang="en">', false);
        $this->front()->get(self::A.'/ja/collections/all')->assertOk()->assertSee('並べ替え');

        $this->front()->get(self::A.'/sitemap.xml')->assertSee('/fr/products/silk-scarf</loc>', false)->assertSee('/ja/products/silk-scarf</loc>', false);

        // Changing the default language: French at "/", English moves under /en
        $this->as()->putJson('/api/admin/settings/languages', ['default' => 'fr', 'published' => ['en']])->assertOk()->assertJsonPath('published', ['en']);
        $this->front()->get(self::A.'/')->assertSee('<html lang="fr">', false);
        $this->front()->get(self::A.'/en')->assertOk()->assertSee('<html lang="en">', false);
    }

    public function test_content_translations_show_on_the_storefront_with_fallback(): void
    {
        $pid = $this->product['id'];
        $this->translate('product', $pid, [
            'title' => 'Foulard en soie', 'option.Color' => 'Couleur', 'option.Color.White' => 'Blanc',
            'seo_title' => 'Foulard en soie | Shop A', 'not.allowed' => 'ignored',
        ])->assertOk();

        $page = $this->front()->get(self::A.'/fr/products/silk-scarf')->assertOk();
        $page->assertSee('Foulard en soie')->assertSee('Couleur')->assertSee('Blanc')
            ->assertSee('Blue')                                              // untranslated value falls back
            ->assertSee('Soft and light.')                                    // untranslated description falls back
            ->assertSee('<title>Foulard en soie | Shop A</title>', false)
            ->assertSee('Ajouter au panier');
        $this->front()->get(self::A.'/products/silk-scarf')->assertSee('Silk Scarf')->assertDontSee('Foulard');

        // Admin API always shows the original
        $this->as()->getJson("/api/admin/products/{$pid}")->assertJsonPath('data.title', 'Silk Scarf');

        // Editor lists fields with progress; clearing a value removes the translation
        $row = collect($this->as()->getJson('/api/admin/translations?type=product&locale=fr')->assertOk()->json('data'))->firstWhere('id', $pid);
        $this->assertSame([3, 'Foulard en soie'], [$row['done'], collect($row['fields'])->firstWhere('key', 'title')['value']]);
        $this->assertContains('option.Color.Blue', array_column($row['fields'], 'key'));
        $this->translate('product', $pid, ['title' => ''])->assertOk();
        $this->front()->get(self::A.'/fr/products/silk-scarf')->assertSee('Silk Scarf');

        // Menus and theme text
        $this->as()->getJson('/api/admin/menus')->assertOk();   // creates the default menus
        $menu = Menu::withoutGlobalScope('store')->where('store_id', $this->a['store_id'])->where('handle', 'main')->first();
        $itemId = $menu->items[1]['id'];
        $this->translate('menu', $menu->id, ["item.{$itemId}" => 'Tout le catalogue'])->assertOk();
        $theme = collect($this->as()->getJson('/api/admin/translations?type=theme&locale=fr')->json('data.0.fields'));
        $heroText = $theme->first(fn ($f) => str_ends_with($f['key'], '.text') && str_starts_with($f['key'], 'sections.'));
        $this->translate('theme', $this->a['store_id'], [$heroText['key'] => 'Des bijoux qui captent la lumière'])->assertOk();
        $this->front()->get(self::A.'/fr')->assertSee('Tout le catalogue')->assertSee('Des bijoux qui captent la lumière')
            ->assertSee('Accueil');                                          // built-in menu item text

        // Guards
        $this->translate('product', $pid, ['title' => 'x'], 'en')->assertStatus(422);   // default language
        $b = $this->postJson('/api/admin/auth/register', ['name' => 'B', 'email' => 'b@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop B'])->json();
        $this->as(['token' => $b['token'], 'store_id' => $b['stores'][0]['id']])->putJson('/api/admin/settings/languages', ['default' => 'en', 'published' => ['fr']]);
        $this->as(['token' => $b['token'], 'store_id' => $b['stores'][0]['id']])
            ->putJson("/api/admin/translations/product/{$pid}", ['locale' => 'fr', 'fields' => ['title' => 'hack']])->assertNotFound();
    }

    public function test_french_checkout_keeps_language_in_links_order_and_emails(): void
    {
        $pid = $this->product['id'];
        $this->translate('product', $pid, ['title' => 'Foulard en soie', 'option.Color' => 'Couleur', 'option.Color.White' => 'Blanc'])->assertOk();
        $rate = ShippingRate::withoutGlobalScope('store')->where('store_id', $this->a['store_id'])->first();
        $this->translate('shipping', $rate->id, ['name' => 'Livraison standard'])->assertOk();
        $variant = $this->product['variants'][0]['id'];

        $add = $this->front()->post(self::A.'/fr/cart/add', ['variant_id' => $variant, 'quantity' => 1]);
        $add->assertRedirect(self::A.'/fr/cart');
        $cart = $add->getCookie('lumee_cart', false)->getValue();

        $this->front()->withUnencryptedCookie('lumee_cart', $cart)->get(self::A.'/fr/cart')->assertOk()
            ->assertSee('Votre panier')->assertSee('Foulard en soie')->assertSee('Blanc');
        $this->front()->withUnencryptedCookie('lumee_cart', $cart)->get(self::A.'/fr/checkout')->assertOk()
            ->assertSee('Livraison standard')->assertSee('Valider la commande')
            ->assertSee('\/fr\/checkout\/shipping-rates', false);   // rate lookups return French names

        // Validation messages in French
        $this->front()->withUnencryptedCookie('lumee_cart', $cart)->post(self::A.'/fr/checkout', ['email' => 'nope'])
            ->assertSessionHasErrors(['email' => 'Le champ e-mail doit être une adresse e-mail valide.']);

        $this->front()->withUnencryptedCookie('lumee_cart', $cart)->post(self::A.'/fr/checkout', [
            'email' => 'buyer@example.com', 'first_name' => 'Amélie', 'last_name' => 'P', 'address1' => '1 Rue', 'city' => 'Paris', 'zip' => '75001',
            'country' => 'FR', 'shipping_rate_id' => $rate->id, 'payment_gateway' => 'test',
        ])->assertRedirectContains('/fr/orders/');

        $order = Order::withoutGlobalScope('store')->with('items')->latest('id')->first();
        $this->assertSame('fr', $order->locale);
        $this->assertSame('Silk Scarf', $order->items[0]->title);            // merchant sees the original
        $this->assertSame('White', $order->items[0]->variant_title);
        $this->assertSame('Standard Shipping', $order->shipping_method['name']);

        $this->front()->get(self::A."/fr/orders/{$order->token}")->assertSee('Merci, Amélie !');
        Mail::assertSent(OrderMail::class, function (OrderMail $m) use ($order) {
            if ($m->type !== 'order_confirmation') {
                return false;
            }
            $html = $m->render();

            return $m->locale === 'fr' && str_contains($html, 'Voir votre commande') && str_contains($html, "/fr/orders/{$order->token}");
        });
        Mail::assertSent(OrderMail::class, fn (OrderMail $m) => $m->type === 'merchant_new_order' && $m->locale === 'zh_CN');
    }
}
