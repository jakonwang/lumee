<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Storefront cart, identified by a cookie token. Cookies are per host,
 * so each store (subdomain / custom domain) naturally gets its own cart.
 */
class CartService
{
    public const COOKIE = 'lumee_cart';

    private ?Cart $cart = null;

    /** Existing cart for this visitor, or null (does not create one). */
    public function current(): ?Cart
    {
        if ($this->cart) {
            return $this->cart;
        }

        $token = request()->cookie(self::COOKIE);
        if (! $token) {
            return null;
        }

        return $this->cart = Cart::with('items.variant.product', 'items.variant.image')->where('token', $token)->first();
    }

    public function currentOrCreate(): Cart
    {
        if ($cart = $this->current()) {
            return $cart;
        }

        $this->cart = Cart::create(['token' => (string) Str::uuid()]);
        $this->cart->setRelation('items', collect());
        Cookie::queue(self::COOKIE, $this->cart->token, 60 * 24 * 30);

        return $this->cart;
    }

    /** @param  array{email?: ?string, name?: ?string, message?: ?string}|null  $gift  recipient of a gift card product */
    public function add(int $variantId, int $quantity, ?string $plan = null, ?array $gift = null): Cart
    {
        // Scoped by BelongsToStore: a variant from another store is simply "not found"
        $variant = ProductVariant::with('product')->findOrFail($variantId);
        abort_unless($variant->product->isSellable(), 404);   // active, and sold in this market
        $b2b = app(\App\B2B\B2B::class);
        abort_if($variant->product->b2b_only && ! $b2b->isBusiness(), 404);
        if ($b2b->hidePrices()) {
            throw ValidationException::withMessages(['quantity' => __('Please sign in to see prices and order.')]);
        }
        $plan = $plan ?: null;
        if (! \App\Subscriptions\Plans::allowed($variant->product, $plan)) {
            throw ValidationException::withMessages(['plan' => __('Please choose a delivery option.')]);
        }

        $cart = $this->currentOrCreate();
        $item = $cart->items()->firstOrNew(['variant_id' => $variant->id, 'plan' => $plan]);
        // Stock covers the one-time and subscription lines of the same variant together
        $other = (int) $cart->items()->where('variant_id', $variant->id)->when($item->exists, fn ($q) => $q->whereKeyNot($item->id))->sum('quantity');
        $newQty = ($item->quantity ?? 0) + $quantity;

        $this->assertStock($variant, $newQty + $other);
        $this->assertRules($variant->id, $newQty);

        $item->quantity = $newQty;
        // Gift cards: send to someone else (the latest recipient entered wins for this line)
        if ($variant->product->is_gift_card && $gift && ! empty($gift['email'])) {
            $item->gift = array_filter(['email' => mb_strtolower(trim($gift['email'])), 'name' => trim((string) ($gift['name'] ?? '')) ?: null,
                'message' => trim((string) ($gift['message'] ?? '')) ?: null]);
        }
        $item->save();

        return $this->refresh();
    }

    public function update(int $itemId, int $quantity): Cart
    {
        $cart = $this->current() ?? abort(404);
        $item = $cart->items()->with('variant')->findOrFail($itemId);

        if ($quantity <= 0) {
            $item->delete();
        } else {
            $this->assertStock($item->variant, $quantity);
            $this->assertRules($item->variant_id, $quantity);
            $item->update(['quantity' => $quantity]);
        }

        return $this->refresh();
    }

    public function clear(): void
    {
        $this->current()?->delete();
        Cookie::queue(Cookie::forget(self::COOKIE));
        $this->cart = null;
    }

    private function refresh(): Cart
    {
        return $this->cart = $this->cart->fresh(['items.variant.product', 'items.variant.image']);
    }

    /** Business buyers: minimum quantity and "sold in multiples of" from their price list. */
    private function assertRules(int $variantId, int $quantity): void
    {
        if ($error = app(\App\Pricing\PriceLists::class)->quantityError($variantId, $quantity)) {
            throw ValidationException::withMessages(['quantity' => $error]);
        }
    }

    private function assertStock(ProductVariant $variant, int $quantity): void
    {
        $variant->loadMissing('product');
        if (\App\Catalog\Preorders::config($variant->product)['enabled']) {
            $a = \App\Catalog\Preorders::available($variant);
            if ($a !== null && $quantity > $a) {
                throw ValidationException::withMessages(['quantity' => $a > 0 ? __('Only :count left to pre-order.', ['count' => $a]) : __('Pre-orders for this item are full.')]);
            }

            return;
        }
        if ($quantity > $variant->inventory_quantity) {
            throw ValidationException::withMessages([
                'quantity' => $variant->inventory_quantity > 0
                    ? __('Only :count left in stock.', ['count' => $variant->inventory_quantity])
                    : __('This item is sold out.'),
            ]);
        }
    }
}
