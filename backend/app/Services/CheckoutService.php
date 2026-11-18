<?php

namespace App\Services;

use App\Models\Cart;
use App\Notifications\OrderNotifier;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Services\TaxService;
use App\Models\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    /**
     * Shipping rates for a destination, cheapest first. The country's own zone wins; countries
     * no zone lists use the "rest of world" zone (if the store has one — otherwise no shipping).
     */
    public function shippingRates(int $subtotal, ?string $country, int $weight = 0, bool $physical = true): Collection
    {
        if (! $physical) {
            return collect([\App\Shipping\Pickup::digital()]);   // nothing to ship
        }
        $store = app(\App\Tenancy\CurrentStore::class)->get();
        $pickup = $store ? \App\Shipping\Pickup::rates($store) : [];
        $zones = ShippingZone::with('rates')->orderBy('position')->get();
        $zone = ($country ? $zones->first(fn (ShippingZone $z) => in_array($country, $z->countries ?? [], true)) : null)
            ?? $zones->first(fn (ShippingZone $z) => $z->isRestOfWorld());

        return ($zone?->rates ?? collect())
            ->filter(fn (ShippingRate $r) => $r->appliesTo($subtotal, $weight))
            ->sortBy('price')
            ->values()
            ->concat($pickup);
    }

    /**
     * Turn the cart into an order. Runs in one transaction with row locks so that
     * two buyers can't both purchase the last item, and order numbers never collide.
     *
     * @param  array{email:string, first_name:string, last_name:string, address1:string, address2?:?string,
     *               city:string, province?:?string, zip:string, country:string, phone?:?string,
     *               shipping_rate_id:int, payment_gateway:string, note?:?string}  $data
     */
    public function placeOrder(Store $store, Cart $cart, array $data): Order
    {
        if ($cart->items->isEmpty()) {
            throw ValidationException::withMessages(['cart' => __('Your bag is empty.')]);
        }

        // The order keeps product/shipping names in the store's default language (what the merchant
        // fulfils from); the shopper's language is remembered for emails.
        $l10n = app(\App\I18n\Localization::class);
        $locale = $l10n->locale();
        $order = $l10n->withoutTranslation(fn () => DB::transaction(function () use ($store, $cart, $data, $locale) {
            // Lock variants in a stable order to avoid deadlocks between concurrent checkouts
            $variants = ProductVariant::with('product', 'image')
                ->whereIn('id', $cart->items->pluck('variant_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lines = [];
            $subtotal = 0;
            foreach ($cart->items as $item) {
                $variant = $variants->get($item->variant_id);
                if (! $variant || $variant->product->status !== 'active') {
                    throw ValidationException::withMessages(['cart' => __('An item in your bag is no longer available.')]);
                }
                if (! \App\Catalog\Preorders::allows($variant, (int) $item->quantity)) {
                    throw ValidationException::withMessages([
                        'cart' => __('Sorry, only :count of “:product” left.', ['count' => $variant->inventory_quantity, 'product' => $variant->product->title]),
                    ]);
                }
                $tier = app(\App\Pricing\PriceLists::class)->unitPrice($variant->id, (int) $item->quantity, (int) $variant->price);
                $unit = \App\Subscriptions\Plans::price($variant->product, $tier, $item->plan);
                $lineTotal = $unit * $item->quantity;
                $subtotal += $lineTotal;
                $lines[] = [$variant, $item->quantity, $lineTotal, $unit, $item->plan];
            }

            // Re-check the discount at purchase time, locking it so usage limits hold under concurrency
            $discount = null;
            if ($cart->discount_code) {
                $discount = Discount::where('code', $cart->discount_code)->lockForUpdate()->first();
                app(DiscountService::class)->assertUsable($discount, $subtotal, $data['email'], $store->currency);
            }

            // Automatic promotions + the code (sale prices are already in $variant->price)
            $discounts = app(DiscountService::class)->discountsFor(array_map(fn ($l) => [$l[0], $l[1], $l[3]], $lines), $discount);
            if ($discount && ! $discounts['code_used']) {
                $discount = null;   // a bigger promotion that can't be combined wins; the code isn't used up
            }

            // Shipping thresholds (e.g. free over $99) apply to the amount after discount
            $afterDiscount = $subtotal - $discounts['amount'];
            $weight = array_sum(array_map(fn ($l) => $l[0]->weight * $l[1], $lines));   // locked variants, grams
            $physical = collect($lines)->contains(fn ($l) => ! $l[0]->product->digital);
            $rate = $this->shippingRates($afterDiscount, $data['country'], $weight, $physical)->firstWhere('id', (int) $data['shipping_rate_id']);
            if (! $rate) {
                throw ValidationException::withMessages(['shipping_rate_id' => __('Please choose a shipping method.')]);
            }
            $totals = app(DiscountService::class)->totals($subtotal, $rate->price, $discounts);

            // Tax (only taxable products; discount spread over items; shipping if the store taxes it)
            $taxableSubtotal = array_sum(array_map(fn ($l) => $l[0]->product->taxable ? $l[2] : 0, $lines));
            $tax = app(\App\B2B\B2B::class)->taxExempt()
                ? ['amount' => 0, 'included' => false, 'lines' => []]   // tax-exempt business buyer
                : app(TaxService::class)->calculate($store,
                    TaxService::taxableAfterDiscount($subtotal, $taxableSubtotal, $totals['discount']),
                    $totals['shipping'], $data['country'], $data['province'] ?? null, $data);
            if (! $tax['included']) {
                $totals['total'] += $tax['amount'];
            }

            $customer = Customer::firstOrNew(['email' => Str::lower($data['email'])]);
            $customer->locale = $locale;
            $customer->fill([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'phone' => $data['phone'] ?? $customer->phone,
            ]);
            if (! empty($data['accepts_marketing'])) {
                $customer->setMarketingConsent(true, 'checkout');   // ticking never unsubscribes; unticked leaves consent as it was
            }
            $customer->orders_count = ($customer->orders_count ?? 0) + 1;
            $customer->save();

            // Serialize order numbering per store
            $lockedStore = Store::whereKey($store->id)->lockForUpdate()->first();
            $number = max(1000, (int) Order::where('store_id', $lockedStore->id)->max('number')) + 1;

            $order = Order::create([
                'number' => $number,
                'token' => (string) Str::uuid(),
                'customer_id' => $customer->id,
                'email' => $customer->email,
                'currency' => $store->currency,
                'locale' => $locale,
                'subtotal' => $subtotal,
                'shipping_total' => $totals['shipping'],
                'discount_code' => $discount?->code,
                'discount_total' => $totals['discount'],
                'discount_lines' => $discounts['lines'] ?: null,
                'tax_total' => $tax['amount'],
                'taxes_included' => $tax['included'],
                'tax_lines' => $tax['lines'] ?: null,
                'total' => $totals['total'],
                'shipping_address' => collect($data)->only(
                    'first_name', 'last_name', 'address1', 'address2', 'city', 'province', 'zip', 'country', 'phone'
                )->all(),
                'shipping_method' => array_filter([
                    'id' => $rate->id, 'name' => $rate->name, 'description' => $rate->description, 'price' => $rate->price,
                    'type' => $rate->id === \App\Shipping\Pickup::DIGITAL_ID ? 'digital' : ($rate->id <= -100 ? 'pickup' : 'shipping'),
                    'pickup' => \App\Shipping\Pickup::location($store, (int) $rate->id),
                ], fn ($v) => $v !== null),
                'payment_gateway' => $data['payment_gateway'],
                'financial_status' => 'pending',
                'fulfillment_status' => 'unfulfilled',
                'note' => $data['note'] ?? null,
            ]);
            $company = app(\App\B2B\B2B::class)->company();
            if ($company || ! empty($data['po_number'])) {
                $order->forceFill(['company_id' => $company?->id, 'po_number' => $data['po_number'] ?? null])->save();
            }
            // Gift card pays (part of) the total; the order total is what's left to pay
            if ($cart->gift_card_code) {
                try {
                    [$card, $take] = app(\App\GiftCards\GiftCards::class)->spend($cart->gift_card_code, $order->total, $order);
                } catch (ValidationException) {
                    $cart->update(['gift_card_code' => null]);
                    throw ValidationException::withMessages(['cart' => __('This gift card can no longer be used.')]);
                }
                if ($take > 0) {
                    $order->forceFill(['gift_card_id' => $card->id, 'gift_card_total' => $take, 'total' => $order->total - $take])->save();
                }
            }
            $discount?->increment('usage_count');
            foreach ($discounts['promotions'] as $p) {
                \App\Models\Promotion::withoutGlobalScope('store')->whereKey($p['id'])->incrementEach(['orders_count' => 1, 'discount_total' => $p['amount']]);
            }

            foreach ($lines as [$variant, $qty, $lineTotal, $unit, $plan]) {
                $order->items()->create([
                    'product_id' => $variant->product_id,
                    'variant_id' => $variant->id,
                    'title' => $variant->product->title,
                    'variant_title' => $variant->title === 'Default' ? null : $variant->title,
                    'sku' => $variant->sku,
                    'image' => $variant->image?->url() ?? $variant->product->featured_image,
                    'price' => $unit,
                    'quantity' => $qty,
                    'plan' => $plan,
                    'total' => $lineTotal,
                    'preorder' => \App\Catalog\Preorders::isPreorder($variant, $qty),
                ]);
                // Reserve stock at order placement (Shopify default behaviour)
                \App\Services\Inventory::change($variant->id, -$qty, 'order', $order->id);
            }

            $promoTitles = collect($discounts['lines'])->where('type', 'promotion')->pluck('title')->implode('、');
            $order->log('placed', __('买家下单').($discount ? __('，使用折扣码 ').$discount->code : '').($promoTitles !== '' ? __('，参与活动：').$promoTitles : ''));

            return $order->load('items');
        }));

        rescue(fn () => app(\App\Risk\RiskAnalyzer::class)->assess($order, request()));   // fraud score (never blocks the sale)
        rescue(fn () => app(\App\Marketing\Affiliates::class)->attribute($order, request()));   // affiliate link
        app(OrderNotifier::class)->orderPlaced($order);
        app(\App\Webhooks\Webhooks::class)->orderEvent($order, 'orders/create');

        return $order;
    }
}
