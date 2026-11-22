<?php

namespace App\B2B;

use App\Http\Controllers\Admin\ManualOrderController;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Store;
use App\Notifications\B2BMail;
use App\Notifications\NotificationSettings;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Quotes (询价): a signed-in buyer sends their bag as a request → the merchant sets prices, shipping
 * and an expiry and sends it → the buyer accepts (order on terms or manual payment) or declines.
 */
class Quotes
{
    public function request(Store $store, Cart $cart, Customer $customer, ?string $note): Quote
    {
        $items = $cart->items->filter(fn ($i) => $i->variant?->product)->map(fn ($i) => [
            'variant_id' => $i->variant_id, 'product_id' => $i->variant->product_id, 'title' => $i->variant->product->title,
            'variant_title' => $i->variant->title === 'Default' ? null : $i->variant->title, 'sku' => $i->variant->sku,
            'quantity' => (int) $i->quantity, 'list_price' => $i->unitPrice(), 'price' => null,
        ])->values()->all();
        if (! $items) {
            throw ValidationException::withMessages(['quote' => __('Your bag is empty.')]);
        }
        $company = $customer->company_id ? \App\Models\Company::find($customer->company_id) : null;

        $quote = DB::transaction(function () use ($store, $customer, $company, $items, $note) {
            $number = max(1000, (int) Quote::withoutGlobalScope('store')->where('store_id', $store->id)->lockForUpdate()->max('number')) + 1;

            return Quote::withoutGlobalScope('store')->forceCreate([
                'store_id' => $store->id, 'token' => (string) Str::uuid(), 'number' => $number, 'customer_id' => $customer->id,
                'company_id' => $company?->id, 'email' => $customer->email, 'status' => 'requested', 'items' => $items,
                'buyer_note' => $note ? mb_substr($note, 0, 2000) : null,
                'shipping_address' => $customer->default_address ?: (($company?->locations ?? [])[0] ?? null),
            ]);
        });
        $this->mail($quote, 'quote_requested', 'merchant');

        return $quote;
    }

    /** Merchant answers: prices (per unit), quantities, shipping, expiry, note → emailed to the buyer. */
    public function send(Quote $quote, array $items, ?int $shipping, ?string $expiresOn, ?string $note): Quote
    {
        if (! in_array($quote->status, ['requested', 'sent'], true)) {
            throw ValidationException::withMessages(['status' => __('这个报价已经结束，不能再修改')]);
        }
        $byVariant = collect($items)->keyBy('variant_id');
        $next = [];
        foreach ($quote->items as $i) {
            $edit = $byVariant->get($i['variant_id']);
            if ($edit && (int) $edit['quantity'] <= 0) {
                continue;   // merchant removed the line
            }
            $next[] = [...$i, 'quantity' => (int) ($edit['quantity'] ?? $i['quantity']), 'price' => isset($edit['price']) ? Money::toMinor($edit['price']) : $i['list_price']];
        }
        if (! $next) {
            throw ValidationException::withMessages(['items' => __('报价里至少要有一件商品')]);
        }
        $quote->forceFill(['items' => $next, 'shipping_price' => $shipping, 'expires_on' => $expiresOn, 'merchant_note' => $note,
            'status' => 'sent', 'sent_at' => now()])->save();
        $this->mail($quote, 'quote_sent', 'buyer');

        return $quote;
    }

    /** Buyer accepts: the quote becomes an order (on account if their company has terms, else manual payment). */
    public function accept(Quote $quote, ?string $po): Order
    {
        if ($quote->status !== 'sent' || $quote->isExpired()) {
            throw ValidationException::withMessages(['quote' => __('This quote can no longer be accepted.')]);
        }
        $store = Store::find($quote->store_id);
        $company = $quote->company_id ? \App\Models\Company::find($quote->company_id) : null;
        $a = $quote->shipping_address ?? [];
        $terms = (int) ($company?->payment_terms ?? 0);

        // Quotes are priced in the store currency, even when the buyer opens the link inside a market
        $order = app(\App\Markets\MarketContext::class)->within(null, fn () => app(ManualOrderController::class)->create($store, [
            'email' => $quote->email, 'first_name' => $a['first_name'] ?? null, 'last_name' => $a['last_name'] ?? null,
            'phone' => $a['phone'] ?? null, 'address1' => $a['address1'] ?? null, 'address2' => $a['address2'] ?? null,
            'city' => $a['city'] ?? null, 'province' => $a['province'] ?? null, 'zip' => $a['zip'] ?? null, 'country' => $a['country'] ?? null,
            'items' => collect($quote->items)->map(fn ($i) => ['variant_id' => $i['variant_id'], 'quantity' => $i['quantity'], 'price' => Money::format((int) $i['price'])])->all(),
            'shipping_name' => $quote->shipping_price !== null ? __('Shipping') : null,
            'shipping_price' => $quote->shipping_price !== null ? Money::format($quote->shipping_price) : 0,
            'charge_tax' => ! ($company?->tax_exempt ?? false), 'payment' => 'pending', 'notify' => true,
            'gateway' => $terms > 0 ? 'terms' : 'manual', 'payment_due_days' => $terms,
            'source' => 'quote', 'quote_name' => $quote->name(), 'company_id' => $company?->id, 'po_number' => $po,
        ], null));

        $quote->forceFill(['status' => 'accepted', 'order_id' => $order->id])->save();
        $this->mail($quote, 'quote_accepted', 'merchant');

        return $order;
    }

    public function decline(Quote $quote): void
    {
        if (in_array($quote->status, ['requested', 'sent'], true)) {
            $quote->forceFill(['status' => 'declined'])->save();
        }
    }

    private function mail(Quote $quote, string $type, string $to): void
    {
        try {
            $store = Store::with('owner')->find($quote->store_id);
            $address = $to === 'merchant' ? NotificationSettings::for($store)->get('merchant_email') : $quote->email;
            $locale = $to === 'merchant' ? ($store->owner?->locale ?: 'zh_CN') : \App\I18n\Localization::storeDefault($store);
            if ($address) {
                Mail::to($address)->locale($locale)->send(new B2BMail($store, $type, ['quote' => $quote]));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
