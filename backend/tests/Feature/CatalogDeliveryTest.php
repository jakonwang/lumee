<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Order;
use App\Models\OrderDownload;
use App\Models\ProductVariant;
use App\Models\ShippingRate;
use App\Notifications\DigitalDownloadMail;
use App\Services\Inventory;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Smart collections, pre-orders, digital products and local pickup. */
class CatalogDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop.lumee.localhost';

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        config(['lumee.payments.test_gateway' => true]);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => 'o@shop.test', 'password' => 'secret-pass', 'store_name' => 'Shop'])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
    }

    private function as(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->a['token'], 'X-Store-Id' => (string) $this->a['store_id'], 'Accept' => 'application/json']);
    }

    private function product(array $data): array
    {
        return $this->as()->postJson('/api/admin/products', ['status' => 'active', ...$data])->assertCreated()->json('data');
    }

    private function shopper(): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->defaultCookies = $this->unencryptedCookies = [];

        return $this;
    }

    private function buy(int $variant, int $qty = 1, ?int $rateId = null)
    {
        $cart = $this->shopper()->post(self::HOST.'/cart/add', ['variant_id' => $variant, 'quantity' => $qty])->getCookie('lumee_cart', false)?->getValue();

        return $this->withUnencryptedCookie('lumee_cart', (string) $cart)->post(self::HOST.'/checkout', [
            'email' => 'b@buyer.test', 'first_name' => 'B', 'last_name' => 'C', 'address1' => '1 St', 'city' => 'X', 'zip' => '1', 'country' => 'US',
            'shipping_rate_id' => $rateId ?? ShippingRate::withoutGlobalScope('store')->where('store_id', $this->a['store_id'])->value('id'), 'payment_gateway' => 'test',
        ]);
    }

    public function test_smart_collections_follow_rules(): void
    {
        $ring = $this->product(['title' => 'Gold Ring', 'price' => 120, 'inventory' => 5, 'tags' => ['Gold'], 'vendor' => 'Aurum']);
        $this->product(['title' => 'Silver Ring', 'price' => 40, 'inventory' => 5, 'tags' => ['silver']]);
        $this->as()->postJson('/api/admin/collections', ['title' => 'Bad', 'rules' => [['field' => 'price', 'op' => 'eq', 'value' => 5]]])->assertStatus(422);

        $gold = $this->as()->postJson('/api/admin/collections', ['title' => 'Gold over 100', 'rules_match' => 'all', 'rules' => [
            ['field' => 'tag', 'op' => 'equals', 'value' => 'gold'], ['field' => 'price', 'op' => 'gt', 'value' => 100],
        ]])->assertCreated()->assertJsonPath('data.smart', true)->json('data');
        $this->assertSame(['Gold Ring'], Collection::withoutGlobalScope('store')->find($gold['id'])->products->pluck('title')->all());

        // New matching product joins automatically; editor can't remove it by hand
        $p3 = $this->product(['title' => 'Gold Chain', 'price' => 300, 'inventory' => 0, 'tags' => ['gold']]);
        $c = Collection::withoutGlobalScope('store')->find($gold['id']);
        $this->assertEqualsCanonicalizing(['Gold Ring', 'Gold Chain'], $c->products->pluck('title')->all());
        $this->as()->putJson("/api/admin/products/{$p3['id']}", ['collection_ids' => []])->assertOk();
        $this->assertCount(2, $c->fresh()->products);

        // Stock rule: follows inventory crossing zero
        $inStock = $this->as()->postJson('/api/admin/collections', ['title' => 'Ready', 'rules' => [['field' => 'stock', 'op' => 'in_stock']]])->json('data');
        $ready = Collection::withoutGlobalScope('store')->find($inStock['id']);
        $this->assertNotContains('Gold Chain', $ready->products->pluck('title')->all());
        Inventory::change($p3['variants'][0]['id'], 3, 'restock');
        $this->assertContains('Gold Chain', $ready->fresh()->products->pluck('title')->all());

        // Retagging removes it; "any" matching; back to manual keeps current members
        $this->as()->putJson("/api/admin/products/{$ring['id']}", ['tags' => ['rose']])->assertOk();
        $this->assertSame(['Gold Chain'], $c->fresh()->products->pluck('title')->all());
        $this->as()->putJson("/api/admin/collections/{$gold['id']}", ['rules_match' => 'any', 'rules' => [['field' => 'vendor', 'op' => 'equals', 'value' => 'aurum'], ['field' => 'title', 'op' => 'contains', 'value' => 'silver']]])->assertOk();
        $this->assertEqualsCanonicalizing(['Gold Ring', 'Silver Ring'], $c->fresh()->products->pluck('title')->all());
        $this->as()->putJson("/api/admin/collections/{$gold['id']}", ['rules' => []])->assertOk()->assertJsonPath('data.smart', false);
        $this->assertCount(2, $c->fresh()->products);
        $this->get(self::HOST.'/collections/'.$c->handle)->assertOk()->assertSee('Silver Ring');
    }

    public function test_preorders_sell_beyond_stock_up_to_the_limit(): void
    {
        $p = $this->product(['title' => 'Comet Ring', 'price' => 80, 'inventory' => 1]);
        $variant = $p['variants'][0]['id'];
        $this->as()->putJson("/api/admin/products/{$p['id']}", ['preorder' => ['enabled' => true, 'ships_at' => now()->addMonth()->toDateString(), 'note' => 'Handmade', 'limit' => 3]])->assertOk()
            ->assertJsonPath('data.preorder.limit', 3);

        $this->buy($variant, 3)->assertRedirect();   // 1 in stock + 2 pre-ordered
        $order = Order::withoutGlobalScope('store')->latest('id')->first();
        $this->assertTrue($order->items->first()->preorder);
        $this->assertSame(-2, ProductVariant::withoutGlobalScope('store')->find($variant)->inventory_quantity);
        $this->get(self::HOST."/orders/{$order->token}")->assertSee('Pre-order')->assertSee('ships around');

        $this->shopper()->get(self::HOST.'/products/comet-ring')->assertSee('Pre-order — ships around', false)->assertSee('Handmade')->assertDontSee('Sold out — get an email');
        $this->shopper()->post(self::HOST.'/cart/add', ['variant_id' => $variant, 'quantity' => 2])->assertSessionHasErrors('quantity');   // only 1 pre-order left
        $this->buy($variant, 1)->assertRedirect()->assertSessionDoesntHaveErrors();
        $this->shopper()->post(self::HOST.'/cart/add', ['variant_id' => $variant, 'quantity' => 1])->assertSessionHasErrors('quantity');   // full

        // Pre-orders off: normal stock rules again
        $this->as()->putJson("/api/admin/products/{$p['id']}", ['preorder' => ['enabled' => false]])->assertOk()->assertJsonPath('data.preorder.enabled', false);
        $this->shopper()->get(self::HOST.'/products/comet-ring')->assertSee('Sold out');
    }

    public function test_digital_products_download_links_and_no_shipping(): void
    {
        $p = $this->product(['title' => 'Ring Guide', 'price' => 9, 'inventory' => 100]);
        $this->as()->post("/api/admin/products/{$p['id']}/files", ['file' => UploadedFile::fake()->create('guide.pdf', 120, 'application/pdf')])->assertCreated()->assertJsonPath('data.filename', 'guide.pdf');
        $this->as()->getJson("/api/admin/products/{$p['id']}")->assertJsonPath('data.digital', true)->assertJsonPath('data.files.0.filename', 'guide.pdf');

        // Digital-only cart: the only delivery option is digital delivery (no shipping charge)
        $this->buy($p['variants'][0]['id'], 1, ShippingRate::withoutGlobalScope('store')->value('id'))->assertSessionHasErrors('shipping_rate_id');
        $this->buy($p['variants'][0]['id'], 1, -1)->assertRedirect();
        $order = Order::withoutGlobalScope('store')->latest('id')->first();
        $this->assertSame(0, (int) $order->shipping_total);
        $this->assertSame('digital', $order->shipping_method['type']);
        $this->assertSame('fulfilled', $order->fulfillment_status);   // nothing to ship
        Mail::assertSent(DigitalDownloadMail::class, fn ($m) => $m->hasTo('b@buyer.test'));

        $dl = OrderDownload::withoutGlobalScope('store')->sole();
        $this->get(self::HOST."/orders/{$order->token}")->assertSee('guide.pdf')->assertSee('/downloads/'.$dl->token);
        for ($i = 0; $i < 5; $i++) {
            $this->get(self::HOST.'/downloads/'.$dl->token)->assertOk()->assertDownload('guide.pdf');
        }
        $this->get(self::HOST.'/downloads/'.$dl->token)->assertStatus(410);
        $this->get(self::HOST.'/downloads/'.str_repeat('x', 48))->assertNotFound();

        // Merchant resets the links
        $this->as()->postJson("/api/admin/orders/{$order->id}/downloads/reset")->assertOk()->assertJsonPath('data.downloads.0.left', 5);
        $this->get(self::HOST.'/downloads/'.$dl->token)->assertOk();
    }

    public function test_local_pickup_option(): void
    {
        $p = $this->product(['title' => 'Bangle', 'price' => 50, 'inventory' => 10]);
        $this->as()->putJson('/api/admin/settings/pickup', ['enabled' => true, 'locations' => []])->assertStatus(422);
        $this->as()->putJson('/api/admin/settings/pickup', ['enabled' => true, 'locations' => [
            ['name' => 'Soho studio', 'address' => '12 Greek St, London', 'hours' => 'Mon–Sat 10–6', 'instructions' => 'Bring your order number'],
        ]])->assertOk()->assertJsonPath('data.locations.0.name', 'Soho studio');

        $cart = $this->shopper()->post(self::HOST.'/cart/add', ['variant_id' => $p['variants'][0]['id'], 'quantity' => 1])->getCookie('lumee_cart', false)->getValue();
        $this->withCredentials()->withUnencryptedCookie('lumee_cart', $cart)->getJson(self::HOST.'/checkout/shipping-rates?country=FR')
            ->assertOk()->assertJsonFragment(['id' => -100, 'name' => 'Pick up at Soho studio']);
        $this->buy($p['variants'][0]['id'], 1, -100)->assertRedirect();
        $order = Order::withoutGlobalScope('store')->latest('id')->first();
        $this->assertSame('pickup', $order->shipping_method['type']);
        $this->assertSame('Soho studio', $order->shipping_method['pickup']['name']);
        $this->assertSame(0, (int) $order->shipping_total);
        $this->get(self::HOST."/orders/{$order->token}")->assertSee('12 Greek St, London')->assertSee('Bring your order number');
        $this->buy($p['variants'][0]['id'], 1, -101)->assertSessionHasErrors('shipping_rate_id');   // no such location
    }
}
