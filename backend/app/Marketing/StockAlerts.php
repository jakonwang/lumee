<?php

namespace App\Marketing;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockAlert;
use App\Models\Store;
use App\Notifications\StockAlertMail;
use Illuminate\Support\Facades\Mail;

/**
 * "Email me when it's back": shoppers sign up on a sold-out variant; when its stock goes from
 * 0 to available, everyone waiting gets one email (in their language) and the alert is closed.
 * Not marketing: it's something they asked for, so it doesn't need email-marketing consent.
 */
class StockAlerts
{
    public function subscribe(ProductVariant $variant, string $email, ?int $customerId, ?string $locale): StockAlert
    {
        return StockAlert::withoutGlobalScope('store')->updateOrCreate(
            ['variant_id' => $variant->id, 'email' => mb_strtolower(trim($email))],
            ['store_id' => $variant->store_id, 'product_id' => $variant->product_id, 'customer_id' => $customerId, 'locale' => $locale,
                'notified_at' => null, 'created_at' => now()],
        );
    }

    /** Called whenever a variant's stock changes; only acts on sold out → in stock. */
    public function restocked(int $variantId, int $before, int $after): void
    {
        if ($before > 0 || $after <= 0) {
            return;
        }
        $waiting = StockAlert::withoutGlobalScope('store')->where('variant_id', $variantId)->whereNull('notified_at')->limit(1000)->get();
        if ($waiting->isEmpty()) {
            return;
        }
        $variant = ProductVariant::withoutGlobalScope('store')->find($variantId);
        $product = $variant ? Product::withoutGlobalScope('store')->find($variant->product_id) : null;
        if (! $product || $product->status !== 'active') {
            return;
        }
        $store = Store::find($variant->store_id);
        foreach ($waiting as $alert) {
            try {
                $locale = $alert->locale ?: \App\I18n\Localization::storeDefault($store);
                app(\App\I18n\Localization::class)->within($store, $locale, fn () => Mail::to($alert->email)->locale($locale)
                    ->send(new StockAlertMail($store, $product, $variant, $alert)));
                $alert->forceFill(['notified_at' => now()])->save();
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
