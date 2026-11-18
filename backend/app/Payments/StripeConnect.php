<?php

namespace App\Payments;

use App\Models\Store;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Stripe Connect (Express accounts): each store is paid into its own Stripe account.
 *
 *  - connect:   create the Express account (once) + an onboarding link hosted by Stripe
 *  - refresh:   read the account back (after onboarding, or from the account.updated webhook)
 *  - dashboard: one-time login link to the merchant's Express dashboard (payouts, balance)
 *
 * Payments are destination charges made on the platform (see StripeGateway): the money is
 * transferred to the store's account and the platform fee is kept as an application fee.
 */
class StripeConnect
{
    /** Countries where Stripe Express accounts can be opened (https://stripe.com/global). Mainland China is not one of them. */
    public const COUNTRIES = [
        'AE', 'AT', 'AU', 'BE', 'BG', 'CA', 'CH', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GB', 'GI', 'GR', 'HK', 'HR',
        'HU', 'IE', 'IT', 'JP', 'LI', 'LT', 'LU', 'LV', 'MT', 'MX', 'MY', 'NL', 'NO', 'NZ', 'PL', 'PT', 'RO', 'SE', 'SG', 'SI',
        'SK', 'TH', 'US',
    ];

    public function enabled(): bool
    {
        return (bool) config('services.stripe.connect') && filled(config('services.stripe.secret'));
    }

    /** Can this store take card payments into its own account right now? */
    public function ready(Store $store): bool
    {
        return $store->stripe_account_id !== null && (bool) ($store->stripe_account['charges_enabled'] ?? false);
    }

    /** not_connected | onboarding | pending | restricted | active */
    public function status(Store $store): string
    {
        $a = $store->stripe_account ?? [];
        if (! $store->stripe_account_id) {
            return 'not_connected';
        }
        if (! ($a['details_submitted'] ?? false)) {
            return 'onboarding';
        }
        if (! ($a['charges_enabled'] ?? false)) {
            return filled($a['disabled_reason'] ?? null) && ! str_contains((string) $a['disabled_reason'], 'pending') ? 'restricted' : 'pending';
        }

        return ($a['currently_due'] ?? 0) > 0 || ! ($a['payouts_enabled'] ?? false) ? 'restricted' : 'active';
    }

    /** Admin payload: what the payments page shows. */
    public function summary(Store $store): array
    {
        $a = $store->stripe_account ?? [];

        return [
            'enabled' => $this->enabled(),
            'status' => $this->status($store),
            'account_id' => $store->stripe_account_id,
            'country' => $a['country'] ?? null,
            'email' => $a['email'] ?? null,
            'default_currency' => $a['default_currency'] ?? null,
            'charges_enabled' => (bool) ($a['charges_enabled'] ?? false),
            'payouts_enabled' => (bool) ($a['payouts_enabled'] ?? false),
            'currently_due' => (int) ($a['currently_due'] ?? 0),
            'disabled_reason' => $a['disabled_reason'] ?? null,
            'countries' => collect(self::COUNTRIES)->map(fn ($c) => ['code' => $c, 'name' => \App\Support\Countries::all()[$c] ?? $c])->sortBy('name')->values(),
        ];
    }

    /** Create the store's Express account if needed and return a Stripe-hosted onboarding URL. */
    public function onboardingUrl(Store $store, ?string $country, string $returnUrl, string $refreshUrl): string
    {
        if (! $store->stripe_account_id) {
            if (! in_array($country, self::COUNTRIES, true)) {
                throw new RuntimeException(__('请选择公司或个人所在的国家/地区'));
            }
            $response = $this->api()
                ->withHeaders(['Idempotency-Key' => "connect-account-{$store->id}-{$country}"])
                ->post('https://api.stripe.com/v1/accounts', [
                    'type' => 'express',
                    'country' => $country,
                    'email' => $store->owner?->email,
                    'capabilities[card_payments][requested]' => 'true',
                    'capabilities[transfers][requested]' => 'true',
                    'business_profile[name]' => $store->name,
                    'business_profile[url]' => $store->storefrontUrl(),
                    'metadata[store_id]' => $store->id,
                ]);
            $this->fail($response, 'Stripe account create');
            $store->forceFill(['stripe_account_id' => $response->json('id'), 'stripe_account' => $this->snapshot($response->json())])->save();
        }

        $response = $this->api()->post('https://api.stripe.com/v1/account_links', [
            'account' => $store->stripe_account_id,
            'type' => 'account_onboarding',
            'return_url' => $returnUrl,
            'refresh_url' => $refreshUrl,
        ]);
        $this->fail($response, 'Stripe account link');

        return $response->json('url');
    }

    /** Re-read the account from Stripe and save its status. */
    public function refresh(Store $store): Store
    {
        if ($store->stripe_account_id) {
            $response = $this->api()->get("https://api.stripe.com/v1/accounts/{$store->stripe_account_id}");
            $this->fail($response, 'Stripe account read');
            $store->forceFill(['stripe_account' => $this->snapshot($response->json())])->save();
        }

        return $store;
    }

    /** account.updated webhook: update whichever store owns the account. */
    public function applyAccount(array $account): void
    {
        $store = Store::where('stripe_account_id', $account['id'] ?? '')->first();
        $store?->forceFill(['stripe_account' => $this->snapshot($account)])->save();
    }

    /** account.application.deauthorized: the merchant disconnected the platform in Stripe. */
    public function deauthorized(string $accountId): void
    {
        Store::where('stripe_account_id', $accountId)->first()?->forceFill(['stripe_account_id' => null, 'stripe_account' => null])->save();
    }

    /** One-time link into the merchant's Stripe Express dashboard. */
    public function dashboardUrl(Store $store): string
    {
        if (! ($store->stripe_account['details_submitted'] ?? false)) {
            throw new RuntimeException(__('请先完成 Stripe 账户资料'));
        }
        $response = $this->api()->post("https://api.stripe.com/v1/accounts/{$store->stripe_account_id}/login_links");
        $this->fail($response, 'Stripe login link');

        return $response->json('url');
    }

    /** Stop paying into this account (the Stripe account itself stays the merchant's). */
    public function disconnect(Store $store): void
    {
        $store->forceFill(['stripe_account_id' => null, 'stripe_account' => null])->save();
    }

    private function snapshot(array $a): array
    {
        return [
            'charges_enabled' => (bool) ($a['charges_enabled'] ?? false),
            'payouts_enabled' => (bool) ($a['payouts_enabled'] ?? false),
            'details_submitted' => (bool) ($a['details_submitted'] ?? false),
            'country' => $a['country'] ?? null,
            'default_currency' => isset($a['default_currency']) ? strtoupper($a['default_currency']) : null,
            'email' => $a['email'] ?? null,
            'currently_due' => count($a['requirements']['currently_due'] ?? []),
            'disabled_reason' => $a['requirements']['disabled_reason'] ?? null,
            'synced_at' => now()->toIso8601String(),
        ];
    }

    private function fail(\Illuminate\Http\Client\Response $response, string $what): void
    {
        if ($response->failed()) {
            report(new RuntimeException("{$what} failed: ".$response->body()));
            throw new RuntimeException(__('Stripe 返回错误：').($response->json('error.message') ?? __('请稍后重试')));
        }
    }

    private function api(): PendingRequest
    {
        return Http::asForm()->withToken((string) config('services.stripe.secret'))->timeout(20);
    }
}
