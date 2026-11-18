<?php

namespace App\Payments;

use Illuminate\Support\Collection;

class GatewayRegistry
{
    /** @var array<string, PaymentGateway> */
    private array $gateways;

    public function __construct(StripeGateway $stripe, PayPalGateway $paypal, AirwallexGateway $airwallex, RazorpayGateway $razorpay, ManualGateway $manual, TestGateway $test, TermsGateway $terms)
    {
        foreach ([$stripe, $paypal, $airwallex, $razorpay, $test, $manual, $terms] as $g) {
            $this->gateways[$g->key()] = $g;
        }
    }

    /** Every gateway, enabled or not (refunds must still work for old orders). @return Collection<string, PaymentGateway> */
    public function all(): Collection
    {
        return collect($this->gateways);
    }

    /** Configured on the platform (keys present) — what a merchant may switch on. @return Collection<string, PaymentGateway> */
    public function configured(): Collection
    {
        // "terms" is offered per business buyer (CheckoutController), not switched on per store
        return collect($this->gateways)->filter(fn (PaymentGateway $g) => $g->key() !== 'terms' && $g->isAvailable());
    }

    /** What buyers can pick at checkout: configured on the platform AND switched on by the store. @return Collection<string, PaymentGateway> */
    public function available(): Collection
    {
        $current = app(\App\Tenancy\CurrentStore::class);
        $store = $current->check() ? $current->getOrFail() : null;
        $settings = $store ? PaymentSettings::for($store) : null;

        return $this->configured()
            ->filter(fn (PaymentGateway $g) => ! $settings || $settings->enabled($g->key()))
            ->filter(fn (PaymentGateway $g) => ! $store || $this->usableBy($g, $store));
    }

    /** Store-specific limits: PayPal currencies; with Stripe Connect, a store needs a ready account. */
    public function usableBy(PaymentGateway $g, \App\Models\Store $store): bool
    {
        if ($g instanceof PayPalGateway || $g instanceof RazorpayGateway) {
            return $g->supportsCurrency($store->currency);
        }
        if ($g instanceof StripeGateway && app(StripeConnect::class)->enabled()) {
            return app(StripeConnect::class)->ready($store);
        }

        return true;
    }

    public function get(string $key): ?PaymentGateway
    {
        // "terms" is per buyer: checkout only accepts it for companies with payment terms
        return $this->available()->get($key) ?? ($key === 'terms' ? $this->gateways['terms'] : null);
    }
}
