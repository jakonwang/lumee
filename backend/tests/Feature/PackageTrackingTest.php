<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ShippingRate;
use App\Notifications\OrderMail;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PackageTrackingTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private array $a;

    private array $product;

    /** What the fake gettrackinfo returns for each number. */
    private array $infos = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.payments.test_gateway' => true, 'services.seventeen_track.key' => 'k17']);
        Mail::fake();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $res = $this->postJson('/api/admin/auth/register', [
            'name' => 'M', 'email' => 'owner@shop-a.test', 'password' => 'secret-pass', 'store_name' => 'Shop A',
        ])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
        $this->product = $this->as()->postJson('/api/admin/products', ['title' => 'Gold Ring', 'price' => 50, 'inventory' => 50, 'status' => 'active'])->json('data');

        Http::fake(function (HttpRequest $r) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            $numbers = array_column($r->data(), 'number');

            return match ($path) {
                '/track/v2.2/register' => Http::response(['code' => 0, 'data' => ['accepted' => array_map(fn ($n) => ['number' => $n, 'carrier' => 3011], $numbers), 'rejected' => []]]),
                '/track/v2.2/gettrackinfo' => Http::response(['code' => 0, 'data' => [
                    'accepted' => array_values(array_map(fn ($n) => ['number' => $n, 'track_info' => $this->infos[$n]], array_filter($numbers, fn ($n) => isset($this->infos[$n])))),
                    'rejected' => array_values(array_map(fn ($n) => ['number' => $n, 'error' => ['code' => -18019902]], array_filter($numbers, fn ($n) => ! isset($this->infos[$n])))),
                ]]),
                default => Http::response(['code' => -1], 404),
            };
        });
    }

    private function as(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->a['token'], 'X-Store-Id' => (string) $this->a['store_id'], 'Accept' => 'application/json']);
    }

    private function shipped(string $number = 'LP00123456789'): Order
    {
        $this->flushHeaders();
        $this->defaultCookies = $this->unencryptedCookies = [];
        $cart = $this->post(self::HOST.'/cart/add', ['variant_id' => $this->product['variants'][0]['id'], 'quantity' => 1])->getCookie('lumee_cart', false)->getValue();
        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/checkout', [
            'email' => 'ana@example.com', 'first_name' => 'Ana', 'last_name' => 'Silva', 'address1' => '1 St', 'city' => 'X', 'zip' => '1', 'country' => 'US',
            'shipping_rate_id' => ShippingRate::withoutGlobalScope('store')->value('id'), 'payment_gateway' => 'test',
        ])->assertRedirect();
        $order = Order::withoutGlobalScope('store')->latest('id')->firstOrFail();
        $this->as()->postJson("/api/admin/orders/{$order->id}/fulfill", ['tracking_company' => 'YunExpress', 'tracking_number' => $number])->assertOk();

        return $order->fresh();
    }

    private function info(string $status, array $events): array
    {
        return [
            'latest_status' => ['status' => $status],
            'tracking' => ['providers' => [['provider' => ['name' => 'YunExpress'], 'events' => $events]]],
        ];
    }

    private function push(Order $order, array $info, ?string $number = null, string $key = 'k17'): \Illuminate\Testing\TestResponse
    {
        $body = json_encode(['event' => 'TRACKING_UPDATED', 'data' => ['number' => $number ?? $order->tracking_number, 'tag' => 'order-'.$order->id, 'track_info' => $info]]);

        return $this->call('POST', '/webhooks/17track', [], [], [], ['HTTP_SIGN' => hash('sha256', $body.'/'.$key), 'CONTENT_TYPE' => 'application/json'], $body);
    }

    public function test_shipping_registers_and_webhook_builds_the_timeline(): void
    {
        $order = $this->shipped();
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/register') && $r->hasHeader('17token', 'k17')
            && $r->data() === [['number' => 'LP00123456789', 'tag' => 'order-'.$order->id]]);
        $this->assertNotNull($order->tracking_registered_at);
        $this->assertSame('NotFound', $order->tracking_status);

        $inTransit = $this->info('InTransit', [
            ['time_utc' => '2026-11-12T08:00:00Z', 'description' => 'Departed from sorting center', 'location' => 'Shenzhen'],
            ['time_utc' => '2026-11-14T09:30:00Z', 'description' => 'Arrived at destination country', 'location' => 'Los Angeles, US'],
        ]);
        $this->push($order, $inTransit, key: 'wrong')->assertStatus(400);
        $this->push($order, $inTransit, number: 'OTHER123')->assertOk();   // number doesn't match → ignored
        $this->assertSame('NotFound', $order->fresh()->tracking_status);

        $this->push($order, $inTransit)->assertOk();
        $order->refresh();
        $this->assertSame(['InTransit', 'YunExpress'], [$order->tracking_status, $order->tracking_carrier]);
        $this->assertSame('Arrived at destination country', $order->tracking_events[0]['description']);   // newest first

        $this->get(self::HOST."/orders/{$order->token}")->assertOk()
            ->assertSee('In transit')->assertSee('Arrived at destination country')->assertSee('Los Angeles, US')->assertSee('Full tracking details');
        $this->as()->getJson("/api/admin/orders/{$order->id}")->assertJsonPath('data.tracking_status', 'InTransit')
            ->assertJsonPath('data.tracking_events.1.location', 'Shenzhen')->assertJsonPath('data.tracking_enabled', true);

        // Delivered: date recorded, buyer emailed once
        $delivered = $this->info('Delivered', [['time_utc' => '2026-11-16T15:00:00Z', 'description' => 'Delivered, front door', 'location' => 'Austin, US']]);
        $this->push($order, $delivered)->assertOk();
        $this->push($order, $delivered)->assertOk();
        $order->refresh();
        $this->assertSame('2026-11-16 15:00', $order->delivered_at->format('Y-m-d H:i'));
        Mail::assertSent(OrderMail::class, fn ($m) => $m->type === 'delivery_confirmation' && $m->hasTo('ana@example.com')
            && $m->envelope()->subject === "Your order {$order->name()} has been delivered");
        $this->assertCount(1, Mail::sent(OrderMail::class, fn ($m) => $m->type === 'delivery_confirmation'));
        $this->assertSame(1, $order->events()->where('type', 'delivered')->count());
    }

    public function test_sync_command_refresh_and_number_change(): void
    {
        $order = $this->shipped('AAA111');
        $this->infos['AAA111'] = $this->info('OutForDelivery', [['time_utc' => '2026-11-17T07:00:00Z', 'description' => 'Out for delivery', 'location' => null]]);

        $this->artisan('lumee:tracking-sync')->expectsOutput('Updated 1 order(s).');
        $this->assertSame('OutForDelivery', $order->fresh()->tracking_status);
        $this->artisan('lumee:tracking-sync')->expectsOutput('Updated 0 order(s).');   // checked less than 3 hours ago

        // Merchant fixes a typo in the number: progress resets and the new parcel is registered
        $this->as()->putJson("/api/admin/orders/{$order->id}/tracking", ['tracking_company' => 'YunExpress', 'tracking_number' => 'BBB222'])->assertOk()
            ->assertJsonPath('data.tracking_status', 'NotFound')->assertJsonPath('data.tracking_events', []);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/register') && $r->data()[0]['number'] === 'BBB222');

        // "Refresh" button
        $this->infos['BBB222'] = $this->info('Exception', [['time_utc' => '2026-11-17T10:00:00Z', 'description' => 'Address incomplete', 'location' => null]]);
        $this->as()->postJson("/api/admin/orders/{$order->id}/tracking/refresh")->assertOk()->assertJsonPath('data.tracking_status', 'Exception');
        $this->assertSame(1, $order->events()->where('type', 'tracking')->where('message', 'like', '%物流异常%')->count());
    }

    public function test_without_a_key_nothing_is_called(): void
    {
        config(['services.seventeen_track.key' => null]);
        $order = $this->shipped();
        Http::assertNothingSent();
        $this->assertNull($order->tracking_status);
        $this->get(self::HOST."/orders/{$order->token}")->assertSee('Track your package')->assertSee('t.17track.net', false);
        $this->as()->postJson("/api/admin/orders/{$order->id}/tracking/refresh")->assertStatus(422);
        $this->artisan('lumee:tracking-sync')->expectsOutput('Package tracking is off (SEVENTEEN_TRACK_KEY is not set).');
        $this->call('POST', '/webhooks/17track', [], [], [], ['HTTP_SIGN' => 'x', 'CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(400);
    }
}
