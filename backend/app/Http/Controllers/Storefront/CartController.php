<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\ShippingRate;
use App\Services\CartService;
use App\Services\DiscountService;
use App\Tenancy\CurrentStore;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    // Request-scoped services are resolved per call: Laravel may reuse a controller instance across requests.
    private function carts(): CartService
    {
        return app(CartService::class);
    }

    private function current(): CurrentStore
    {
        return app(CurrentStore::class);
    }

    public function show(): View
    {
        // Lowest subtotal that unlocks a free shipping rate (drives the "away from free shipping" bar)
        $freeShippingAt = ShippingRate::where('price', 0)->whereNotNull('min_subtotal')->min('min_subtotal');

        $cart = $this->carts()->current();
        $discounts = $cart && $cart->items->isNotEmpty() ? app(DiscountService::class)->discountsFor($cart, $cart->discount()) : null;

        return view($this->current()->themeView('cart'), [
            'suggestions' => app(\App\Catalog\Upsells::class)->forCart($this->carts()->current()),
            'cart' => $cart,
            'discounts' => $discounts,
            'nextTier' => $cart ? app(\App\Promotions\Promotions::class)->nextTier($cart->subtotal()) : null,
            'freeShippingAt' => $freeShippingAt ? app(\App\Markets\MarketContext::class)->money((int) $freeShippingAt) : null,
            'giftCard' => $cart?->giftCard(),
            'wholesale' => app(\App\Pricing\PriceLists::class)->active() ? ['name' => app(\App\Pricing\PriceLists::class)->name(), 'minimum' => app(\App\Pricing\PriceLists::class)->minimum()] : null,
            'loyalty' => (function () {
                $customer = \App\Customers\CustomerSession::user();
                $store = $this->current()->getOrFail();
                $s = \App\Loyalty\Points::settings($store);

                return $s['enabled'] && $customer ? ['points' => (int) $customer->points, 'min' => $s['min_redeem'],
                    'value' => \App\Loyalty\Points::value($store, (int) $customer->points), 'earn_rate' => $s['earn_rate']] : ($s['enabled'] ? ['guest' => true, 'earn_rate' => $s['earn_rate']] : null);
            })(),
        ]);
    }

    public function add(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'variant_id' => ['required', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'plan' => ['nullable', 'string', 'max:20'],
            'gift.email' => ['nullable', 'email', 'max:255'],
            'gift.name' => ['nullable', 'string', 'max:100'],
            'gift.message' => ['nullable', 'string', 'max:500'],
        ]);

        $qty = (int) ($data['quantity'] ?? 1);
        $cart = $this->carts()->add((int) $data['variant_id'], $qty, $data['plan'] ?? null, $data['gift'] ?? null);
        $variant = $cart->items->firstWhere('variant_id', (int) $data['variant_id'])?->variant;
        app(\App\Analytics\Visits::class)->addToCart($variant?->product_id);
        app(\App\Testing\Experiments::class)->cart($variant?->product_id);

        return redirect('/cart')->with('status', __('Added to your bag.'))->with('tracking_add', $variant ? [
            'id' => $variant->id, 'name' => $variant->product->title, 'price' => (int) $variant->price, 'quantity' => $qty,
        ] : null);
    }

    /** "Frequently bought together": add several products at once (one of each). */
    public function addMany(Request $request): RedirectResponse
    {
        $data = $request->validate(['variant_ids' => ['required', 'array', 'min:1', 'max:4'], 'variant_ids.*' => ['integer']]);
        foreach (array_unique(array_map('intval', $data['variant_ids'])) as $id) {
            $this->carts()->add($id, 1);
            $pid = \App\Models\ProductVariant::find($id)?->product_id;
            app(\App\Analytics\Visits::class)->addToCart($pid);
            app(\App\Testing\Experiments::class)->cart($pid);
        }

        return redirect('/cart')->with('status', __('Added to your bag.'));
    }

    public function applyDiscount(Request $request, DiscountService $discounts): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40']], [
            'code.required' => __('Please enter a discount code.'),
        ]);
        $cart = $this->carts()->current();
        if (! $cart || $cart->items->isEmpty()) {
            return back();
        }

        // The same box takes gift card codes (a discount code with the same text wins)
        $currency = $this->current()->getOrFail()->currency;
        if (! $discounts->find($data['code'])) {
            $card = app(\App\GiftCards\GiftCards::class)->findUsable($data['code'], $currency);
            if ($card) {
                $cart->update(['gift_card_code' => $card->code]);

                return back()->with('status', __('Gift card applied. Remaining balance is used at checkout.'));
            }
        }

        try {
            $discount = $discounts->assertUsable($discounts->find($data['code']), $cart->subtotal(), null, $currency);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Show the message next to the code field, not as a generic error
            return back()->withErrors(['discount_code' => $e->getMessage()])->withInput();
        }

        $cart->update(['discount_code' => $discount->code]);
        if ($discounts->discountsFor($cart->fresh(['items.variant.product']), $discount)['code_blocked']) {
            return back()->with('status', __('Code :code can’t be combined with the current promotion, which saves you more.', ['code' => $discount->code]));
        }

        return back()->with('status', __('Discount :code applied.', ['code' => $discount->code]));
    }

    public function removeGiftCard(): RedirectResponse
    {
        $this->carts()->current()?->update(['gift_card_code' => null]);

        return back();
    }

    public function removeDiscount(): RedirectResponse
    {
        $this->carts()->current()?->update(['discount_code' => null]);

        return back();
    }

    public function update(Request $request, int $item): RedirectResponse
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:0', 'max:10000']]);
        $this->carts()->update($item, (int) $data['quantity']);

        return redirect('/cart');
    }
}
