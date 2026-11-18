<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ShippingRate;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TrackingFeedTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private array $a;

    private array $product;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.payments.test_gateway' => true]);
        Mail::fake();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $res = $this->postJson('/api/admin/auth/register', [
            'name' => 'M', 'email' => 'owner@shop-a.test', 'password' => 'secret-pass', 'store_name' => 'Shop A',
        ])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
        $this->product = $this->as()->postJson('/api/admin/products', [
            'title' => 'Silk Scarf & Co', 'description' => '<p>Soft <b>silk</b>.</p>', 'status' => 'active', 'vendor' => 'Lumee',
            'options' => [['name' => 'Color', 'values' => ['White', 'Blue']]],
            'variants' => [
                ['options' => ['Color' => 'White'], 'price' => 40, 'compare_at_price' => 50, 'inventory' => 5, 'sku' => 'SS-W'],
                ['options' => ['Color' => 'Blue'], 'price' => 40, 'inventory' => 0],
            ],
        ])->assertCreated()->json('data');
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

    public function test_settings_validate_and_pixels_load_with_events(): void
    {
        $this->as()->getJson('/api/admin/settings/tracking')->assertOk()
            ->assertJsonPath('settings', ['ga4' => null, 'meta' => null, 'tiktok' => null, 'consent' => false])
            ->assertJsonPath('feeds.0.url', 'http://shop-a.lumee.localhost:8000/feeds/google.xml');

        // Nothing configured → no third-party scripts at all
        $this->front()->get(self::HOST.'/products/silk-scarf-co')->assertOk()->assertDontSee('googletagmanager', false)->assertDontSee('fbevents', false);

        $this->as()->putJson('/api/admin/settings/tracking', ['ga4' => 'G-123"</script>', 'meta' => null, 'tiktok' => null, 'consent' => false])
            ->assertStatus(422)->assertJsonValidationErrors('ga4');
        $this->as()->putJson('/api/admin/settings/tracking', ['ga4' => null, 'meta' => '12ab', 'tiktok' => null, 'consent' => false])->assertStatus(422);
        $this->as()->putJson('/api/admin/settings/tracking', ['ga4' => ' g-ab12cd34ef ', 'meta' => '123456789012345', 'tiktok' => 'c1a2b3c4d5e6f7g8', 'consent' => false])
            ->assertOk()->assertJsonPath('settings.ga4', 'G-AB12CD34EF')->assertJsonPath('settings.tiktok', 'C1A2B3C4D5E6F7G8');

        $page = $this->front()->get(self::HOST.'/products/silk-scarf-co')->assertOk();
        $page->assertSee('"ga4":"G-AB12CD34EF"', false)->assertSee('"meta":"123456789012345"', false)->assertSee('fbevents.js', false)
            ->assertSee("lumeeTrack('view_item'", false)->assertSee('"price":40', false);

        // Add to cart → event on the cart page; checkout → begin_checkout; order page → purchase
        $variant = $this->product['variants'][0]['id'];
        $add = $this->front()->post(self::HOST.'/cart/add', ['variant_id' => $variant, 'quantity' => 2]);
        $cart = $add->getCookie('lumee_cart', false)->getValue();
        $this->withUnencryptedCookie('lumee_cart', $cart)->withSession(['tracking_add' => ['id' => $variant, 'name' => 'Silk Scarf & Co', 'price' => 4000, 'quantity' => 2]])
            ->get(self::HOST.'/cart')->assertSee("lumeeTrack('add_to_cart'", false)->assertSee('"value":80', false);
        $this->withUnencryptedCookie('lumee_cart', $cart)->get(self::HOST.'/checkout')->assertSee("lumeeTrack('begin_checkout'", false);
        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/checkout', [
            'email' => 'b@example.com', 'first_name' => 'A', 'last_name' => 'B', 'address1' => '1 St', 'city' => 'X', 'zip' => '1', 'country' => 'US',
            'shipping_rate_id' => ShippingRate::withoutGlobalScope('store')->value('id'), 'payment_gateway' => 'test',
        ])->assertRedirect();
        $order = Order::withoutGlobalScope('store')->sole();
        $this->front()->get(self::HOST."/orders/{$order->token}")->assertSee("lumeeTrack('purchase'", false)->assertSee('"transaction_id":"#1001"', false);
    }

    public function test_consent_banner_mode(): void
    {
        $this->as()->putJson('/api/admin/settings/tracking', ['ga4' => 'G-AB12CD34EF', 'meta' => null, 'tiktok' => null, 'consent' => true])->assertOk();
        $this->front()->get(self::HOST.'/')->assertOk()->assertSee('"consent":true', false)->assertSee('lumee-consent', false)
            ->assertSee('We use cookies to measure visits', false);
    }

    public function test_google_merchant_feed_lists_each_variant(): void
    {
        $this->as()->postJson('/api/admin/products', ['title' => 'Draft', 'price' => 10, 'status' => 'draft'])->assertCreated();
        $res = $this->front()->get(self::HOST.'/feeds/google.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $xml = simplexml_load_string($res->getContent());
        $this->assertNotFalse($xml);
        $items = $xml->channel->item;
        $this->assertCount(2, $items);   // draft products are left out

        $g = fn ($item) => $item->children('http://base.google.com/ns/1.0');
        $white = $g($items[0]);
        $this->assertSame('SS-W', (string) $white->id);
        $this->assertSame('P'.$this->product['id'], (string) $white->item_group_id);
        $this->assertSame('Silk Scarf & Co - White', (string) $white->title);
        $this->assertSame('Soft silk.', (string) $white->description);
        $this->assertSame('in_stock', (string) $white->availability);
        $this->assertSame(['50.00 USD', '40.00 USD'], [(string) $white->price, (string) $white->sale_price]);
        $this->assertSame('Lumee', (string) $white->brand);
        $this->assertStringEndsWith('/products/silk-scarf-co?variant='.$this->product['variants'][0]['id'], (string) $white->link);

        $blue = $g($items[1]);
        $this->assertSame('V'.$this->product['variants'][1]['id'], (string) $blue->id);
        $this->assertSame('out_of_stock', (string) $blue->availability);
        $this->assertSame('no', (string) $blue->identifier_exists);

        // Translated feed under the language prefix
        $this->as()->putJson('/api/admin/settings/languages', ['default' => 'en', 'published' => ['fr']])->assertOk();
        $this->as()->putJson("/api/admin/translations/product/{$this->product['id']}", ['locale' => 'fr', 'fields' => ['title' => 'Foulard en soie']])->assertOk();
        $fr = simplexml_load_string($this->front()->get(self::HOST.'/fr/feeds/google.xml')->assertOk()->getContent());
        $this->assertStringStartsWith('Foulard en soie', (string) $g($fr->channel->item[0])->title);
        $this->assertStringContainsString('/fr/products/', (string) $g($fr->channel->item[0])->link);
    }
}
