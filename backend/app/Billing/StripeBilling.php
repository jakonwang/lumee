<?php

namespace App\Billing;

use App\Models\PlatformInvoice;
use App\Models\Store;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Platform subscriptions on Stripe Billing (merchants pay Lumée). Card details stay on
 * Stripe's hosted Checkout page. Stripe is the source of truth: webhooks keep our
 * `subscriptions` row and platform invoices in sync.
 */
class StripeBilling
{
    private const API = 'https://api.stripe.com/v1';

    public function __construct(private Billing $billing) {}

    private function api(): PendingRequest
    {
        return Http::asForm()->withToken((string) config('services.stripe.secret'))->timeout(20);
    }

    private function call(string $method, string $path, array $params = [], ?string $idempotencyKey = null): array
    {
        $req = $this->api();
        if ($idempotencyKey) {
            $req = $req->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }
        $res = $method === 'get' ? $req->get(self::API.$path, $params) : $req->post(self::API.$path, $params);
        if ($res->failed()) {
            report(new RuntimeException("Stripe {$path} failed: ".$res->body()));
            throw new RuntimeException(__('Stripe：').($res->json('error.message') ?? __('请求失败，请稍后再试')));
        }

        return $res->json();
    }

    /** One Stripe Product per plan, created on first use. */
    public function productId(string $plan): string
    {
        return Cache::rememberForever("stripe_billing_product_{$plan}", fn () => $this->call('post', '/products', [
            'name' => 'Lumée '.$this->billing->plan($plan)['name'],
            'metadata[plan]' => $plan,
        ], "lumee-product-{$plan}")['id']);
    }

    private function priceData(string $prefix, string $plan, string $interval): array
    {
        return [
            "{$prefix}[currency]" => 'usd',
            "{$prefix}[product]" => $this->productId($plan),
            "{$prefix}[unit_amount]" => $this->billing->plan($plan)[$interval],
            "{$prefix}[recurring][interval]" => $interval,
        ];
    }

    /** Hosted Checkout for a new subscription. Charging starts when the free trial ends. */
    public function checkout(Store $store, string $plan, string $interval, string $email, string $returnUrl): string
    {
        $existing = $this->billing->subscription($store);
        $params = [
            'mode' => 'subscription',
            'client_reference_id' => (string) $store->id,
            'line_items[0][quantity]' => 1,
            ...$this->priceData('line_items[0][price_data]', $plan, $interval),
            'metadata[store_id]' => $store->id,
            'subscription_data[metadata][store_id]' => $store->id,
            'subscription_data[metadata][plan]' => $plan,
            'subscription_data[metadata][interval]' => $interval,
            'success_url' => $returnUrl.'?checkout=success',
            'cancel_url' => $returnUrl.'?checkout=cancel',
        ];
        $params += $existing?->stripe_customer_id ? ['customer' => $existing->stripe_customer_id] : ['customer_email' => $email];
        // Keep the rest of the free trial (Stripe needs the trial end to be at least 2 days away)
        if ($store->trial_ends_at && $store->trial_ends_at->gt(now()->addDays(2))) {
            $params['subscription_data[trial_end]'] = $store->trial_ends_at->timestamp;
        }

        return $this->call('post', '/checkout/sessions', $params)['url'];
    }

    /** Switch plan / interval on the live subscription, prorated. */
    public function change(Subscription $sub, string $plan, string $interval): void
    {
        $remote = $this->call('get', "/subscriptions/{$sub->stripe_subscription_id}");
        $updated = $this->call('post', "/subscriptions/{$sub->stripe_subscription_id}", [
            'items[0][id]' => $remote['items']['data'][0]['id'],
            ...$this->priceData('items[0][price_data]', $plan, $interval),
            'proration_behavior' => 'create_prorations',
            'cancel_at_period_end' => 'false',
            'metadata[plan]' => $plan,
            'metadata[interval]' => $interval,
        ]);
        $this->sync($updated);
    }

    public function setCancelAtPeriodEnd(Subscription $sub, bool $cancel): void
    {
        $this->sync($this->call('post', "/subscriptions/{$sub->stripe_subscription_id}", [
            'cancel_at_period_end' => $cancel ? 'true' : 'false',
        ]));
    }

    /** Transaction fees: added to the customer's next subscription invoice. Returns the invoice item id. */
    public function addInvoiceItem(Subscription $sub, int $usdCents, string $description, string $key): string
    {
        return $this->call('post', '/invoiceitems', [
            'customer' => $sub->stripe_customer_id,
            'subscription' => $sub->stripe_subscription_id,
            'amount' => $usdCents,
            'currency' => 'usd',
            'description' => $description,
        ], $key)['id'];
    }

    public function fetchSubscription(string $id): array
    {
        return $this->call('get', "/subscriptions/{$id}");
    }

    // ── Webhook handling ──────────────────────────────────────────────────────

    public function handle(array $event): void
    {
        $object = $event['data']['object'] ?? [];

        match ($event['type']) {
            'checkout.session.completed' => ($object['mode'] ?? null) === 'subscription' && ! empty($object['subscription'])
                ? $this->sync($this->fetchSubscription($object['subscription']), $object['customer'] ?? null)
                : null,
            'customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted' => $this->sync($object),
            'invoice.paid' => $this->invoicePaid($object),
            'invoice.payment_failed' => $this->paymentFailed($object),
            default => null,
        };
    }

    /** Mirror a Stripe subscription object into our row. Ignores subscriptions that aren't Lumée's. */
    public function sync(array $s, ?string $customer = null): ?Subscription
    {
        $store = Store::find((int) ($s['metadata']['store_id'] ?? 0));
        $plan = $s['metadata']['plan'] ?? null;
        if (! $store || ! $plan || ! $this->billing->plan($plan)) {
            return null;
        }
        $status = match ($s['status'] ?? '') {
            'active', 'trialing' => 'active',
            'past_due', 'unpaid' => 'past_due',
            'canceled', 'incomplete_expired' => 'canceled',
            default => null,   // incomplete: payment not done yet — don't activate
        };
        if ($status === null) {
            return $this->billing->subscription($store);
        }
        // API versions differ on where the period lives (subscription vs. item)
        $periodEnd = $s['current_period_end'] ?? ($s['items']['data'][0]['current_period_end'] ?? null);
        $existing = $this->billing->subscription($store);

        return $this->billing->upsert($store, [
            'plan' => $plan,
            'interval' => $s['metadata']['interval'] ?? ($s['items']['data'][0]['price']['recurring']['interval'] ?? 'month'),
            'status' => $status,
            'provider' => 'stripe',
            'current_period_end' => $periodEnd ? Carbon::createFromTimestamp($periodEnd) : null,
            'cancel_at_period_end' => (bool) ($s['cancel_at_period_end'] ?? false),
            'past_due_since' => $status === 'past_due' ? ($existing?->past_due_since ?? now()) : null,
            'ended_at' => $status === 'canceled' ? now() : null,
            'stripe_customer_id' => $customer ?? (is_string($s['customer'] ?? null) ? $s['customer'] : $existing?->stripe_customer_id),
            'stripe_subscription_id' => $s['id'],
        ]);
    }

    private function invoicePaid(array $inv): void
    {
        $sub = Subscription::withoutGlobalScope('store')->where('stripe_subscription_id', $inv['subscription'] ?? '')->first();
        if (! $sub || (int) ($inv['amount_paid'] ?? 0) <= 0) {
            return;
        }
        $store = Store::find($sub->store_id);

        // Fee invoices we added as invoice items are now paid too
        $itemIds = collect($inv['lines']['data'] ?? [])->pluck('invoice_item')->filter()->all();
        if ($itemIds) {
            PlatformInvoice::withoutGlobalScope('store')->whereIn('provider_ref', $itemIds)->update(['status' => 'paid', 'paid_at' => now()]);
        }

        if (! PlatformInvoice::withoutGlobalScope('store')->where('provider_ref', $inv['id'])->exists()) {
            $line = collect($inv['lines']['data'] ?? [])->first(fn ($l) => empty($l['invoice_item']));
            $subscriptionAmount = (int) collect($inv['lines']['data'] ?? [])->filter(fn ($l) => empty($l['invoice_item']))->sum('amount');
            $this->billing->recordSubscriptionInvoice(
                $store, $sub,
                isset($line['period']['start']) ? Carbon::createFromTimestamp($line['period']['start']) : null,
                isset($line['period']['end']) ? Carbon::createFromTimestamp($line['period']['end']) : null,
                $inv['id'],
                $subscriptionAmount ?: (int) $inv['amount_paid'],
            );
        }
        if ($sub->status === 'past_due') {
            $this->billing->upsert($store, ['status' => 'active', 'past_due_since' => null]);
        }
    }

    private function paymentFailed(array $inv): void
    {
        $sub = Subscription::withoutGlobalScope('store')->where('stripe_subscription_id', $inv['subscription'] ?? '')->first();
        if (! $sub) {
            return;
        }
        $store = Store::find($sub->store_id);
        $this->billing->upsert($store, ['status' => 'past_due', 'past_due_since' => $sub->past_due_since ?? now()]);
        BillingMailer::notify($store, 'payment_failed');
    }
}
