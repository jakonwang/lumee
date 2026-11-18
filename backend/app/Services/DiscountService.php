<?php

namespace App\Services;

use App\Models\Discount;
use App\Models\Order;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * Validates discount codes for a cart. All messages are shopper-facing (English storefront).
 * Called when the code is applied in the cart AND again when the order is placed
 * (with a row lock), so limits can't be exceeded by racing checkouts.
 */
class DiscountService
{
    public function find(string $code): ?Discount
    {
        return Discount::where('code', Discount::normalizeCode($code))->first();   // store-scoped
    }

    /** @throws ValidationException (key "discount_code") when the code can't be used */
    public function assertUsable(?Discount $discount, int $subtotal, ?string $email = null, string $currency = 'USD'): Discount
    {
        $fail = fn (string $msg) => throw ValidationException::withMessages(['discount_code' => $msg]);

        if (! $discount) {
            $fail(__('This discount code is not valid.'));
        }

        match ($discount->status()) {
            'disabled' => $fail(__('This discount code is not valid.')),
            'scheduled' => $fail(__('This discount code is not active yet.')),
            'expired' => $fail(__('This discount code has expired.')),
            'used_up' => $fail(__('This discount code has reached its usage limit.')),
            default => null,
        };

        if ($discount->min_subtotal !== null && $subtotal < $discount->min_subtotal) {
            $fail(__('Spend :amount or more to use this code.', ['amount' => Money::display($discount->min_subtotal, $currency)]));
        }

        if ($email && $discount->once_per_customer
            && Order::where('discount_code', $discount->code)->where('email', strtolower($email))->exists()) {
            $fail(__('You have already used this discount code.'));
        }

        return $discount;
    }

    /**
     * Every discount on a bag: automatic promotions (tiers, bundles) and the discount code.
     * Sale prices are already in the variant prices.
     *
     * @param  iterable<array{0:\App\Models\ProductVariant, 1:int}>|\App\Models\Cart  $items  cart, or [variant, quantity] pairs
     */
    public function discountsFor(\App\Models\Cart|array $items, ?Discount $code): array
    {
        $lines = [];
        $pairs = $items instanceof \App\Models\Cart ? $items->items->filter(fn ($i) => $i->variant)->map(fn ($i) => [$i->variant, $i->quantity, $i->unitPrice()]) : $items;
        foreach ($pairs as $pair) {
            [$variant, $qty] = $pair;
            $lines[] = ['product_id' => (int) $variant->product_id, 'price' => (int) ($pair[2] ?? $variant->price), 'quantity' => (int) $qty];
        }

        return app(\App\Promotions\Promotions::class)->discountsFor($lines, $code);
    }

    /**
     * Totals for a bag given its discounts (see discountsFor).
     *
     * @return array{discount:int, shipping:int, total:int}
     */
    public function totals(int $subtotal, int $shipping, array $discounts): array
    {
        $off = min($subtotal, (int) $discounts['amount']);
        if ($discounts['free_shipping']) {
            $shipping = 0;
        }

        return ['discount' => $off, 'shipping' => $shipping, 'total' => max(0, $subtotal - $off) + $shipping];
    }
}
