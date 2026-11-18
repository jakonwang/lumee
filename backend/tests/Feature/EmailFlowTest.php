<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\EmailFlowEnrollment;
use App\Models\Order;
use App\Models\ShippingRate;
use App\Models\Store;
use App\Notifications\CampaignMail;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailFlowTest extends TestCase
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
        Store::whereKey($this->a['store_id'])->update(['trial_ends_at' => now()->addYears(2)]);   // time travel below mustn't end the trial
        $this->product = $this->as()->postJson('/api/admin/products', ['title' => 'Gold Ring', 'price' => 50, 'inventory' => 50, 'status' => 'active'])->json('data');
    }

    private function as(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->a['token'], 'X-Store-Id' => (string) $this->a['store_id'], 'Accept' => 'application/json']);
    }

    private function flow(string $type): array
    {
        return collect($this->as()->getJson('/api/admin/flows')->assertOk()->json('data'))->firstWhere('type', $type);
    }

    /** Switch a flow on, keeping its starter steps (optionally overriding fields). */
    private function enable(string $type, array $extra = [], ?array $steps = null): array
    {
        $flow = $this->as()->getJson('/api/admin/flows/'.$this->flow($type)['id'])->json('data');

        return $this->as()->putJson("/api/admin/flows/{$flow['id']}", [
            'enabled' => true, 'stop_on_order' => $flow['stop_on_order'], 'win_back_days' => $flow['win_back_days'],
            'steps' => $steps ?? $flow['steps'], ...$extra,
        ])->assertOk()->json('data');
    }

    private function subscribe(string $email): void
    {
        $this->flushHeaders();
        $this->postJson(self::HOST.'/newsletter', ['email' => $email])->assertOk();
    }

    private function buy(string $email): Order
    {
        $this->flushHeaders();
        $this->defaultCookies = $this->unencryptedCookies = [];
        $cart = $this->post(self::HOST.'/cart/add', ['variant_id' => $this->product['variants'][0]['id'], 'quantity' => 1])->getCookie('lumee_cart', false)->getValue();
        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/checkout', [
            'email' => $email, 'first_name' => 'A', 'last_name' => 'B', 'address1' => '1 St', 'city' => 'X', 'zip' => '1', 'country' => 'US',
            'shipping_rate_id' => ShippingRate::withoutGlobalScope('store')->value('id'), 'payment_gateway' => 'test', 'accepts_marketing' => '1',
        ])->assertRedirect();

        return Order::withoutGlobalScope('store')->latest('id')->firstOrFail();
    }

    private function sent(string $to): array
    {
        return collect(Mail::sent(CampaignMail::class, fn ($m) => $m->hasTo($to)))->map(fn ($m) => $m->campaign->subject)->values()->all();
    }

    public function test_starter_flows_and_welcome_series_timing(): void
    {
        $flows = $this->as()->getJson('/api/admin/flows')->assertOk();
        $this->assertSame(['welcome', 'post_purchase', 'win_back', 'birthday', 'browse_abandon'], array_column($flows->json('data'), 'type'));
        $this->assertFalse($flows->json('data.0.enabled'));
        $this->assertSame('Welcome to Shop A', $flows->json('data.0.steps.0.subject'));
        $flows->assertJsonPath('quota.limit', 500)->assertJsonPath('quota.used', 0);

        // Off: nothing happens
        $this->subscribe('early@example.com');
        $this->assertSame(0, EmailFlowEnrollment::withoutGlobalScope('store')->count());

        $this->enable('welcome');
        $this->subscribe('ana@example.com');
        $this->artisan('lumee:run-flows')->expectsOutput('Sent 1 automated email(s).');
        $this->assertSame(['Welcome to Shop A'], $this->sent('ana@example.com'));
        $this->assertSame([], $this->sent('early@example.com'));   // subscribed before the flow existed

        $this->travel(48)->hours();
        $this->artisan('lumee:run-flows')->expectsOutput('Sent 0 automated email(s).');
        $this->travel(25)->hours();
        $this->artisan('lumee:run-flows')->expectsOutput('Sent 1 automated email(s).');
        $this->assertSame(['Welcome to Shop A', 'Our customers’ favourites'], $this->sent('ana@example.com'));
        $this->assertSame('completed', EmailFlowEnrollment::withoutGlobalScope('store')->sole()->status);

        // Unsubscribe link works like campaigns, and stats add up
        $mail = collect(Mail::sent(CampaignMail::class, fn ($m) => $m->hasTo('ana@example.com')))->first();
        $this->assertStringContainsString('/email/unsubscribe/', $mail->render());
        $this->assertSame(2, $this->flow('welcome')['stats']['sent']);
        // Flow steps don't show up as campaigns
        $this->as()->getJson('/api/admin/campaigns')->assertJsonCount(0, 'data');
    }

    public function test_welcome_stops_on_order_or_unsubscribe(): void
    {
        $this->enable('welcome');
        $this->subscribe('buyer@example.com');
        $this->subscribe('leaver@example.com');
        $this->artisan('lumee:run-flows');

        $this->travel(1)->hours();
        $this->buy('buyer@example.com');
        Customer::withoutGlobalScope('store')->where('email', 'leaver@example.com')->update(['accepts_marketing' => false]);

        $this->travel(80)->hours();
        $this->artisan('lumee:run-flows')->expectsOutput('Sent 0 automated email(s).');
        $reasons = EmailFlowEnrollment::withoutGlobalScope('store')->with('customer')->get()->mapWithKeys(fn ($e) => [$e->customer->email => $e->stop_reason]);
        $this->assertSame(['buyer@example.com' => 'ordered', 'leaver@example.com' => 'unsubscribed'], $reasons->all());
    }

    public function test_post_purchase_sends_after_first_order_only(): void
    {
        $this->as()->postJson('/api/admin/discounts', ['code' => 'THANKS10', 'type' => 'percentage', 'value' => 10])->assertCreated();
        $flow = $this->as()->getJson('/api/admin/flows/'.$this->flow('post_purchase')['id'])->json('data');
        $this->as()->putJson("/api/admin/flows/{$flow['id']}", ['enabled' => true, 'steps' => [[...$flow['steps'][0], 'discount_code' => 'nope']]])
            ->assertStatus(422)->assertJsonValidationErrors('steps.0.discount_code');
        $this->enable('post_purchase', [], [[...$flow['steps'][0], 'discount_code' => 'thanks10']]);

        $this->buy('ana@example.com');
        $this->buy('ana@example.com');   // second order doesn't enroll again
        $this->assertSame(1, EmailFlowEnrollment::withoutGlobalScope('store')->count());

        $this->travel(73)->hours();
        $this->artisan('lumee:run-flows')->expectsOutput('Sent 1 automated email(s).');
        $mail = collect(Mail::sent(CampaignMail::class, fn ($m) => $m->hasTo('ana@example.com')))->sole();
        $this->assertSame('Thank you for your order', $mail->campaign->subject);
        $this->assertStringContainsString('THANKS10', $mail->render());
    }

    public function test_win_back_after_inactivity_and_stops_when_they_return(): void
    {
        $this->enable('win_back', ['win_back_days' => 30]);
        $this->buy('old@example.com');
        $this->buy('recent@example.com');
        Order::withoutGlobalScope('store')->whereHas('customer', fn ($q) => $q->where('email', 'old@example.com'))->update(['paid_at' => now()->subDays(40)]);

        $this->artisan('lumee:run-flows')->expectsOutput('Sent 1 automated email(s).');
        $this->assertSame(['We miss you'], $this->sent('old@example.com'));
        $this->assertSame([], $this->sent('recent@example.com'));

        cache()->forget('flows:win-back-scan');
        $this->artisan('lumee:run-flows')->expectsOutput('Sent 0 automated email(s).');   // asked once per lapse
    }

    public function test_monthly_allowance_limits_campaigns_and_flows(): void
    {
        config(['plans.trial.marketing_emails' => 1]);
        $this->enable('welcome');
        $this->subscribe('a@example.com');
        $this->subscribe('b@example.com');
        $this->artisan('lumee:run-flows')->expectsOutput('Sent 1 automated email(s).');
        $waiting = EmailFlowEnrollment::withoutGlobalScope('store')->where('next_position', 1)->sole();
        $this->assertTrue($waiting->next_send_at->isAfter(now()->startOfMonth()->addMonth()));   // tries again next month

        $c = $this->as()->postJson('/api/admin/campaigns', ['subject' => 'Sale', 'body' => 'Hi', 'audience' => 'subscribers'])->json('data');
        $this->as()->postJson("/api/admin/campaigns/{$c['id']}/send")->assertStatus(422)->assertJsonValidationErrors('campaign');
        $this->as()->getJson('/api/admin/campaigns')->assertJsonPath('quota.used', 1)->assertJsonPath('quota.limit', 1);
    }

    public function test_step_validation_and_isolation(): void
    {
        $flow = $this->flow('welcome');
        $step = ['delay_hours' => 0, 'subject' => 'Hi'];
        $this->as()->putJson("/api/admin/flows/{$flow['id']}", ['enabled' => true, 'steps' => []])->assertStatus(422);
        $this->as()->putJson("/api/admin/flows/{$flow['id']}", ['enabled' => true, 'steps' => array_fill(0, 6, $step)])->assertStatus(422);
        $this->as()->putJson("/api/admin/flows/{$flow['id']}", ['enabled' => true, 'steps' => [[...$step, 'button_url' => 'javascript:x']]])->assertStatus(422);
        $this->as()->putJson("/api/admin/flows/{$flow['id']}", ['enabled' => true, 'win_back_days' => 7, 'steps' => [$step]])->assertStatus(422);

        $saved = $this->as()->putJson("/api/admin/flows/{$flow['id']}", ['enabled' => true, 'steps' => [$step, [...$step, 'subject' => 'Again', 'delay_hours' => 24]]])
            ->assertOk()->json('data');
        $this->assertSame(['Hi', 'Again'], array_column($saved['steps'], 'subject'));

        $b = $this->postJson('/api/admin/auth/register', ['name' => 'B', 'email' => 'b@shop-b.test', 'password' => 'secret-pass', 'store_name' => 'Shop B'])->json();
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$b['token'], 'X-Store-Id' => (string) $b['stores'][0]['id']])
            ->getJson("/api/admin/flows/{$flow['id']}")->assertNotFound();
    }
}
