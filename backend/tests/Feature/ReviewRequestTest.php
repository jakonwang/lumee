<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ProductReview;
use App\Models\ShippingRate;
use App\Notifications\OrderMail;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ReviewRequestTest extends TestCase
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
        \App\Models\Store::whereKey($this->a['store_id'])->update(['trial_ends_at' => now()->addYear()]);   // time travel below mustn't end the trial
        $this->product = $this->as()->postJson('/api/admin/products', ['title' => 'Gold Ring', 'price' => 50, 'inventory' => 50, 'status' => 'active'])->json('data');
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
        $this->defaultCookies = $this->unencryptedCookies = [];

        return $this;
    }

    /** Paid (test gateway) and shipped order. */
    private function shippedOrder(string $email = 'ana@example.com'): Order
    {
        $cart = $this->front()->post(self::HOST.'/cart/add', ['variant_id' => $this->product['variants'][0]['id'], 'quantity' => 1])->getCookie('lumee_cart', false)->getValue();
        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/checkout', [
            'email' => $email, 'first_name' => 'Ana', 'last_name' => 'Silva', 'address1' => '1 St', 'city' => 'X', 'zip' => '1', 'country' => 'US',
            'shipping_rate_id' => ShippingRate::withoutGlobalScope('store')->value('id'), 'payment_gateway' => 'test',
        ])->assertRedirect();
        $order = Order::withoutGlobalScope('store')->latest('id')->firstOrFail();
        $this->as()->postJson("/api/admin/orders/{$order->id}/fulfill", ['tracking_number' => 'X1'])->assertOk();

        return $order->fresh();
    }

    private function requests(): \Illuminate\Support\Collection
    {
        return collect(Mail::sent(OrderMail::class, fn ($m) => $m->type === 'review_request'));
    }

    public function test_sent_once_after_the_delay_with_review_links(): void
    {
        $order = $this->shippedOrder();

        $this->travel(5)->days();
        $this->artisan('lumee:review-requests')->expectsOutput('Sent 0 review request(s).');   // default: 7 days
        $this->travel(3)->days();
        $this->artisan('lumee:review-requests')->expectsOutput('Sent 1 review request(s).');
        $this->artisan('lumee:review-requests')->expectsOutput('Sent 0 review request(s).');   // only once

        $this->assertCount(1, $this->requests());
        $mail = $this->requests()->first();
        $this->assertTrue($mail->hasTo('ana@example.com'));
        $this->assertSame('How are you enjoying your order?', $mail->envelope()->subject);
        $html = $mail->render();
        $this->assertStringContainsString('How is everything, Ana?', $html);
        $this->assertStringContainsString("/products/gold-ring/review?order={$order->token}", $html);
        $this->assertNotNull($order->fresh()->review_requested_at);
        $this->travelBack();

        // The link opens the form with name and email filled in
        $this->front()->get(self::HOST."/products/gold-ring/review?order={$order->token}")
            ->assertRedirect('/products/gold-ring#reviews')->assertSessionHas('review_prefill', ['author' => 'Ana Silva', 'email' => 'ana@example.com']);
        $this->withSession(['review_prefill' => ['author' => 'Ana Silva', 'email' => 'ana@example.com']])->get(self::HOST.'/products/gold-ring')
            ->assertSee('value="ana@example.com"', false)->assertSee('<details class="rv-write"  open', false);
        // A made-up or foreign token just shows the page
        $this->front()->get(self::HOST.'/products/gold-ring/review?order='.\Illuminate\Support\Str::uuid())->assertRedirect('/products/gold-ring#reviews')->assertSessionMissing('review_prefill');
    }

    public function test_delay_setting_switch_and_already_reviewed(): void
    {
        $this->as()->putJson('/api/admin/reviews/settings', ['enabled' => true, 'auto_publish' => 'verified', 'request_delay_days' => 14])->assertOk()
            ->assertJsonPath('settings.request_delay_days', 14);
        $this->as()->putJson('/api/admin/reviews/settings', ['enabled' => true, 'auto_publish' => 'verified', 'request_delay_days' => 5])->assertStatus(422);

        $first = $this->shippedOrder('one@example.com');
        $this->travel(8)->days();
        $this->artisan('lumee:review-requests')->expectsOutput('Sent 0 review request(s).');   // 14 days now
        $this->travelBack();

        // Switched off in notification settings → nothing sent, and nothing marked (can still go out if turned back on)
        $settings = $this->as()->getJson('/api/admin/settings/notifications')->json('settings');
        $this->as()->putJson('/api/admin/settings/notifications', [...$settings, 'enabled' => [...$settings['enabled'], 'review_request' => false]])->assertOk();
        $this->travel(15)->days();
        $this->artisan('lumee:review-requests')->expectsOutput('Sent 0 review request(s).');
        $this->assertNull($first->fresh()->review_requested_at);
        $this->as()->putJson('/api/admin/settings/notifications', [...$settings, 'enabled' => [...$settings['enabled'], 'review_request' => true]])->assertOk();
        $this->travelBack();

        // Already reviewed everything → not asked
        $second = $this->shippedOrder('two@example.com');
        ProductReview::withoutGlobalScope('store')->create(['store_id' => $this->a['store_id'], 'product_id' => $this->product['id'], 'author' => 'Two', 'email' => 'two@example.com', 'rating' => 5, 'body' => 'Lovely ring indeed', 'status' => 'published']);
        $this->travel(15)->days();
        $this->artisan('lumee:review-requests')->expectsOutput('Sent 1 review request(s).');   // only one@
        $this->assertCount(1, $this->requests());
        $this->assertTrue($this->requests()->first()->hasTo('one@example.com'));
        $this->assertNotNull($second->fresh()->review_requested_at);

        // Reviews turned off entirely → no requests
        $third = $this->shippedOrder('three@example.com');
        $this->as()->putJson('/api/admin/reviews/settings', ['enabled' => false, 'auto_publish' => 'verified', 'request_delay_days' => 3])->assertOk();
        $this->travel(5)->days();
        $this->artisan('lumee:review-requests')->expectsOutput('Sent 0 review request(s).');
        $this->assertNull($third->fresh()->review_requested_at);
    }

    public function test_merchant_can_preview_the_email(): void
    {
        $this->as()->getJson('/api/admin/settings/notifications')->assertOk()
            ->assertJsonFragment(['key' => 'review_request', 'audience' => 'customer']);
        $this->as()->get('/api/admin/notifications/review_request/preview')->assertOk()->assertSee('Write a review');
    }
}
