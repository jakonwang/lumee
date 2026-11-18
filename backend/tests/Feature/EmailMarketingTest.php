<?php

namespace Tests\Feature;

use App\Marketing\CampaignSender;
use App\Models\Customer;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\ShippingRate;
use App\Notifications\CampaignMail;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailMarketingTest extends TestCase
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
        $this->product = $this->as()->postJson('/api/admin/products', ['title' => 'Gold Ring', 'price' => 50, 'inventory' => 50, 'status' => 'active'])->json('data');
    }

    private function as(?array $a = null): static
    {
        $a ??= $this->a;
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$a['token'], 'X-Store-Id' => (string) $a['store_id'], 'Accept' => 'application/json']);
    }

    private function front(): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->defaultCookies = $this->unencryptedCookies = [];

        return $this;
    }

    private function subscribe(string $email, string $source = 'form'): \Illuminate\Testing\TestResponse
    {
        return $this->front()->postJson(self::HOST.'/newsletter', ['email' => $email, 'source' => $source]);
    }

    private function campaign(array $data = []): array
    {
        return $this->as()->postJson('/api/admin/campaigns', [
            'subject' => 'New arrivals', 'heading' => 'Fresh for spring', 'body' => "Hello!\n\nSee [the lookbook](https://example.com/look).",
            'button_label' => 'Shop now', 'button_url' => '/collections/all', 'product_ids' => [$this->product['id']], 'audience' => 'subscribers', ...$data,
        ])->assertCreated()->json('data');
    }

    public function test_signup_sources_popup_code_and_checkout_consent(): void
    {
        $this->subscribe('ANA@example.com', 'popup')->assertOk()->assertJsonPath('code', null);
        $ana = Customer::withoutGlobalScope('store')->where('email', 'ana@example.com')->sole();
        $this->assertSame([true, 'popup'], [$ana->accepts_marketing, $ana->marketing_source]);

        // Popup with a code: shown on the storefront, code returned after signing up
        $this->as()->postJson('/api/admin/discounts', ['code' => 'WELCOME10', 'type' => 'percentage', 'value' => 10])->assertCreated();
        $this->as()->putJson('/api/admin/campaigns/popup', ['enabled' => true, 'heading' => 'Get 10% off', 'text' => 'Join us', 'button' => 'Subscribe', 'discount_code' => 'nope', 'delay' => 3])
            ->assertStatus(422)->assertJsonValidationErrors('discount_code');
        $this->as()->putJson('/api/admin/campaigns/popup', ['enabled' => true, 'heading' => 'Get 10% off', 'text' => 'Join us', 'button' => 'Subscribe', 'discount_code' => 'welcome10', 'delay' => 3])
            ->assertOk()->assertJsonPath('popup.discount_code', 'WELCOME10');
        $this->front()->get(self::HOST.'/')->assertOk()->assertSee('id="nl-pop"', false)->assertSee('Get 10% off');
        $this->front()->get(self::HOST.'/checkout')->assertDontSee('id="nl-pop"', false);
        $this->subscribe('bo@example.com', 'popup')->assertJsonPath('code', 'WELCOME10')->assertJsonPath('message', 'Thanks for subscribing! Use code WELCOME10 at checkout.');

        // Plain form: redirect back with a message; honeypot stores nothing; invalid email rejected
        $this->front()->from(self::HOST.'/')->post(self::HOST.'/newsletter', ['email' => 'cy@example.com'])->assertRedirect()->assertSessionHas('newsletter_status', 'Thanks for subscribing!');
        $this->front()->postJson(self::HOST.'/newsletter', ['email' => 'bot@example.com', 'website' => 'x'])->assertOk();
        $this->subscribe('not-an-email')->assertStatus(422);
        $this->assertNull(Customer::withoutGlobalScope('store')->where('email', 'bot@example.com')->first());

        // Checkout box
        $cart = $this->front()->post(self::HOST.'/cart/add', ['variant_id' => $this->product['variants'][0]['id'], 'quantity' => 1])->getCookie('lumee_cart', false)->getValue();
        $this->withUnencryptedCookie('lumee_cart', $cart)->get(self::HOST.'/checkout')->assertSee('name="accepts_marketing"', false);
        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/checkout', [
            'email' => 'dee@example.com', 'first_name' => 'D', 'last_name' => 'E', 'address1' => '1 St', 'city' => 'X', 'zip' => '1', 'country' => 'US',
            'shipping_rate_id' => ShippingRate::withoutGlobalScope('store')->value('id'), 'payment_gateway' => 'test', 'accepts_marketing' => '1',
        ])->assertRedirect();
        $dee = Customer::withoutGlobalScope('store')->where('email', 'dee@example.com')->sole();
        $this->assertSame([true, 'checkout'], [$dee->accepts_marketing, $dee->marketing_source]);

        // Audience counts: 4 subscribers, 1 has ordered
        $audiences = collect($this->as()->getJson('/api/admin/campaigns')->assertOk()->json('audiences'))->pluck('count', 'key');
        $this->assertSame(['subscribers' => 4, 'customers' => 1, 'prospects' => 3], $audiences->all());
    }

    public function test_send_now_tracks_opens_clicks_and_unsubscribes(): void
    {
        foreach (['a@example.com', 'b@example.com', 'c@example.com'] as $e) {
            $this->subscribe($e);
        }
        Customer::withoutGlobalScope('store')->create(['store_id' => $this->a['store_id'], 'email' => 'nope@example.com', 'accepts_marketing' => false]);

        $c = $this->campaign();
        $this->as()->get("/api/admin/campaigns/{$c['id']}/preview")->assertOk()->assertSee('Fresh for spring')->assertSee('Gold Ring');
        $this->as()->postJson("/api/admin/campaigns/{$c['id']}/test")->assertOk()->assertJsonPath('message', '测试邮件已发送到 owner@shop-a.test');
        Mail::assertSent(CampaignMail::class, fn ($m) => $m->test && $m->hasTo('owner@shop-a.test') && $m->envelope()->subject === '[TEST] New arrivals');

        $this->as()->postJson("/api/admin/campaigns/{$c['id']}/send")->assertOk()
            ->assertJsonPath('data.status', 'sent')->assertJsonPath('data.recipients_count', 3)->assertJsonPath('data.sent_count', 3);
        Mail::assertSent(CampaignMail::class, fn ($m) => ! $m->test, 3);
        Mail::assertNotSent(CampaignMail::class, fn ($m) => $m->hasTo('nope@example.com'));

        $r = EmailCampaignRecipient::withoutGlobalScope('store')->where('email', 'a@example.com')->sole();
        $mail = null;
        Mail::assertSent(CampaignMail::class, function ($m) use ($r, &$mail) {
            return $m->hasTo('a@example.com') && ($mail = $m);
        });
        $html = $mail->render();
        $this->assertStringContainsString("/email/unsubscribe/{$r->token}", $html);
        $this->assertStringContainsString("/email/open/{$r->token}.gif", $html);
        $this->assertStringContainsString("/email/click/{$r->token}?u=", $html);
        $this->assertStringNotContainsString('href="https://example.com/look"', $html);   // tracked
        $this->assertSame('List-Unsubscribe=One-Click', $mail->headers()->text['List-Unsubscribe-Post']);

        // Opens and clicks are counted once per person
        $this->front()->get(self::HOST."/email/open/{$r->token}.gif")->assertOk()->assertHeader('Content-Type', 'image/gif');
        $this->front()->get(self::HOST."/email/open/{$r->token}.gif");
        $url = 'https://example.com/look';
        $sig = CampaignSender::linkSignature($r->token, $url);
        $this->front()->get(self::HOST."/email/click/{$r->token}?".http_build_query(['u' => $url, 's' => $sig]))->assertRedirect($url);
        $this->front()->get(self::HOST."/email/click/{$r->token}?".http_build_query(['u' => 'https://evil.example', 's' => $sig]))->assertNotFound();
        $other = EmailCampaignRecipient::withoutGlobalScope('store')->where('email', 'b@example.com')->sole();
        $this->front()->get(self::HOST."/email/click/{$other->token}?".http_build_query(['u' => $url, 's' => CampaignSender::linkSignature($other->token, $url)]));   // click without open
        $this->as()->getJson("/api/admin/campaigns/{$c['id']}")
            ->assertJsonPath('data.opened_count', 2)->assertJsonPath('data.clicked_count', 2)->assertJsonPath('data.open_rate', 66.7);

        // Unsubscribe: GET only asks, POST does it; mail clients' one-click POST gets 204
        $this->front()->get(self::HOST."/email/unsubscribe/{$r->token}")->assertOk()->assertSee('Unsubscribe a@example.com from marketing emails?');
        $this->assertTrue(Customer::withoutGlobalScope('store')->where('email', 'a@example.com')->value('accepts_marketing'));
        $this->front()->post(self::HOST."/email/unsubscribe/{$r->token}")->assertOk()->assertSee('has been unsubscribed');
        $this->front()->post(self::HOST."/email/unsubscribe/{$other->token}", ['List-Unsubscribe' => 'One-Click'])->assertNoContent();
        $this->assertSame(1, Customer::withoutGlobalScope('store')->where('accepts_marketing', true)->count());
        $this->as()->getJson("/api/admin/campaigns/{$c['id']}")->assertJsonPath('data.unsubscribed_count', 2);

        // Sent campaigns are read-only
        $this->as()->putJson("/api/admin/campaigns/{$c['id']}", ['subject' => 'X', 'audience' => 'subscribers'])->assertStatus(422);
    }

    public function test_schedule_segments_and_isolation(): void
    {
        $this->subscribe('buyer@example.com');
        $this->subscribe('browser@example.com');
        Customer::withoutGlobalScope('store')->where('email', 'buyer@example.com')->update(['orders_count' => 2]);

        $c = $this->campaign(['audience' => 'prospects']);
        $this->as()->postJson("/api/admin/campaigns/{$c['id']}/send", ['scheduled_at' => now()->addHour()->toIso8601String()])->assertOk()->assertJsonPath('data.status', 'scheduled');
        $this->artisan('lumee:send-campaigns')->expectsOutput('Started 0 scheduled campaign(s).');
        Mail::assertNothingSent();

        $this->as()->postJson("/api/admin/campaigns/{$c['id']}/unschedule")->assertJsonPath('data.status', 'draft');
        $this->as()->postJson("/api/admin/campaigns/{$c['id']}/send", ['scheduled_at' => now()->addHour()->toIso8601String()])->assertOk();
        $this->travel(2)->hours();
        $this->artisan('lumee:send-campaigns')->expectsOutput('Started 1 scheduled campaign(s).');
        Mail::assertSent(CampaignMail::class, 1);
        Mail::assertSent(CampaignMail::class, fn ($m) => $m->hasTo('browser@example.com'));
        $this->travelBack();

        // Validation and another store
        $this->as()->postJson('/api/admin/campaigns', ['subject' => '', 'audience' => 'subscribers'])->assertStatus(422);
        $this->as()->postJson('/api/admin/campaigns', ['subject' => 'X', 'audience' => 'everyone'])->assertStatus(422);
        $this->as()->postJson('/api/admin/campaigns', ['subject' => 'X', 'audience' => 'subscribers', 'button_url' => 'javascript:alert(1)'])->assertStatus(422);
        $empty = $this->as()->postJson('/api/admin/campaigns', ['subject' => 'X', 'audience' => 'subscribers'])->json('data.id');
        $this->as()->postJson("/api/admin/campaigns/{$empty}/send")->assertStatus(422);

        $b = $this->postJson('/api/admin/auth/register', ['name' => 'B', 'email' => 'b@shop-b.test', 'password' => 'secret-pass', 'store_name' => 'Shop B'])->json();
        $other = ['token' => $b['token'], 'store_id' => $b['stores'][0]['id']];
        $this->as($other)->getJson("/api/admin/campaigns/{$c['id']}")->assertNotFound();
        $this->assertSame(0, collect($this->as($other)->getJson('/api/admin/campaigns')->json('audiences'))->sum('count'));
        $this->assertSame(1, EmailCampaign::withoutGlobalScope('store')->where('store_id', $this->a['store_id'])->where('status', 'sent')->count());
    }
}
