<?php

namespace App\B2B;

use App\Customers\CustomerSession;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Store;
use App\Pricing\PriceLists;
use App\Tenancy\CurrentStore;

/**
 * The B2B side of a storefront request (request-scoped): the signed-in buyer, their company,
 * whether they're a business buyer, payment terms, tax exemption, and the store's B2B settings.
 * stores.settings['b2b'] = {hide_prices: guests must sign in to see prices, applications: wholesale sign-up form,
 *                           tag: tag given to approved buyers, terms: default Net days for approved companies}
 */
class B2B
{
    public const DEFAULTS = ['hide_prices' => false, 'applications' => false, 'tag' => 'wholesale', 'terms' => 0];

    private bool $loaded = false;

    private ?Customer $customer = null;

    private ?Company $company = null;

    public static function settings(Store $store): array
    {
        $s = array_replace(self::DEFAULTS, $store->settings['b2b'] ?? []);

        return ['hide_prices' => (bool) $s['hide_prices'], 'applications' => (bool) $s['applications'],
            'tag' => (string) ($s['tag'] ?: 'wholesale'), 'terms' => max(0, (int) $s['terms'])];
    }

    /** For tests / jobs: act as this customer. */
    public function actAs(?Customer $customer): void
    {
        $this->customer = $customer;
        $this->company = $customer?->company_id ? Company::withoutGlobalScope('store')->find($customer->company_id) : null;
        $this->loaded = true;
        app(PriceLists::class)->reset();
    }

    public function customer(): ?Customer
    {
        $this->load();

        return $this->customer;
    }

    public function company(): ?Company
    {
        $this->load();

        return $this->company;
    }

    /** Tags that select price lists: the buyer's own tags plus their company's tag. */
    public function pricingTags(): array
    {
        $tags = $this->customer()?->tags ?? [];
        if ($this->company()?->tag) {
            $tags[] = $this->company()->tag;
        }

        return $tags;
    }

    /** A business buyer: belongs to a company or gets a price list. */
    public function isBusiness(): bool
    {
        return $this->company() !== null || app(PriceLists::class)->active() !== null;
    }

    public function taxExempt(): bool
    {
        return (bool) ($this->company()?->tax_exempt || $this->customer()?->tax_exempt);
    }

    /** Net days the buyer may pay in (0 = none). */
    public function termsDays(): int
    {
        return (int) ($this->company()?->payment_terms ?? 0);
    }

    /** Guests must sign in to see prices and buy (B2B-only stores). */
    public function hidePrices(): bool
    {
        $current = app(CurrentStore::class);

        return $current->check() && self::settings($current->get())['hide_prices'] && ! $this->customer();
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;
        $this->customer = rescue(fn () => CustomerSession::user(), null, false);
        $this->company = $this->customer?->company_id ? Company::find($this->customer->company_id) : null;
    }
}
