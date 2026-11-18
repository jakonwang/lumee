<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RetentionCohortTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 1000;

    private function register(string $email, string $store): array
    {
        $res = $this->postJson('/api/admin/auth/register', [
            'name' => 'M', 'email' => $email, 'password' => 'secret-pass', 'store_name' => $store,
        ])->assertCreated();

        return ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
    }

    private function as(array $m): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$m['token'], 'X-Store-Id' => (string) $m['store_id']]);
    }

    private function order(int $store, string $email, string $at, string $status = 'paid'): void
    {
        Order::withoutGlobalScope('store')->forceCreate([
            'store_id' => $store, 'number' => ++$this->n, 'token' => bin2hex(random_bytes(16)), 'email' => $email,
            'shipping_address' => ['name' => 'X'], 'payment_gateway' => 'manual', 'currency' => 'USD',
            'subtotal' => 1000, 'total' => 1000, 'financial_status' => $status, 'fulfillment_status' => 'unfulfilled',
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    public function test_monthly_cohorts_and_repeat_summary(): void
    {
        Carbon::setTestNow('2026-11-15 12:00:00');
        $a = $this->register('a@example.com', 'Shop A');
        $b = $this->register('b@example.com', 'Shop B');
        $sid = $a['store_id'];

        $this->order($sid, 'alice@x.test', '2026-09-10 10:00:00');
        $this->order($sid, 'Alice@x.test', '2026-10-05 10:00:00');   // same buyer, different case
        $this->order($sid, 'alice@x.test', '2026-11-02 10:00:00');
        $this->order($sid, 'bob@x.test', '2026-09-20 10:00:00');
        $this->order($sid, 'carol@x.test', '2026-03-01 10:00:00');   // first bought before the window
        $this->order($sid, 'carol@x.test', '2026-10-01 10:00:00');
        $this->order($sid, 'dave@x.test', '2026-11-03 10:00:00', 'pending'); // not paid → ignored
        $this->order($b['store_id'], 'erin@x.test', '2026-11-01 10:00:00');  // other store

        $res = $this->as($a)->getJson('/api/admin/analytics/retention?months=6')->assertOk()
            ->assertJsonPath('months', 6)
            ->assertJsonPath('summary.customers', 3)
            ->assertJsonPath('summary.repeat_customers', 2)
            ->assertJsonPath('summary.repeat_rate', 66.7)
            ->assertJsonPath('summary.orders_per_customer', 2)
            ->assertJsonCount(6, 'cohorts')
            ->assertJsonPath('cohorts.0.month', '2026-11')
            ->assertJsonPath('cohorts.5.month', '2026-06');

        $sep = collect($res->json('cohorts'))->firstWhere('month', '2026-09');
        $this->assertSame(2, $sep['customers']);
        $this->assertEquals([100, 50, 50], $sep['retention']);
        $this->assertSame(0, collect($res->json('cohorts'))->firstWhere('month', '2026-10')['customers']);

        $this->as($a)->getJson('/api/admin/analytics/retention?months=12')->assertOk()->assertJsonCount(12, 'cohorts');
        $this->as($b)->getJson('/api/admin/analytics/retention')->assertOk()->assertJsonPath('summary.customers', 1);
        Carbon::setTestNow();
    }
}
