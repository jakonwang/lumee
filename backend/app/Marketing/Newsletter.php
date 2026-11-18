<?php

namespace App\Marketing;

use App\Models\Customer;
use App\Models\Discount;
use App\Models\Store;

/**
 * Email subscribers (customers with accepts_marketing) and the signup popup.
 * Popup settings live in stores.settings['popup'].
 */
class Newsletter
{
    public const POPUP_DEFAULTS = [
        'enabled' => false,
        'heading' => 'Get 10% off your first order',
        'text' => 'Join our list for new arrivals, styling tips and members-only offers.',
        'button' => 'Subscribe',
        'discount_code' => null,
        'delay' => 5,              // seconds before it opens
    ];

    public static function popup(Store $store): array
    {
        $s = array_merge(self::POPUP_DEFAULTS, array_intersect_key($store->settings['popup'] ?? [], self::POPUP_DEFAULTS));
        $s['enabled'] = (bool) $s['enabled'];
        $s['delay'] = max(0, min(60, (int) $s['delay']));

        return $s;
    }

    public static function savePopup(Store $store, array $data): void
    {
        $settings = $store->settings ?? [];
        $settings['popup'] = [
            'enabled' => (bool) $data['enabled'],
            'heading' => trim($data['heading']),
            'text' => trim((string) ($data['text'] ?? '')),
            'button' => trim($data['button']),
            'discount_code' => filled($data['discount_code'] ?? null) ? Discount::normalizeCode($data['discount_code']) : null,
            'delay' => (int) $data['delay'],
        ];
        $store->settings = $settings;
        $store->save();
    }

    /** Subscribe an email (creating a customer record if needed). Returns true when newly subscribed. */
    public function subscribe(string $email, string $source): bool
    {
        $customer = Customer::firstOrNew(['email' => mb_strtolower(trim($email))]);   // store-scoped
        $was = (bool) $customer->accepts_marketing && $customer->exists;
        if (! $customer->exists) {
            $customer->locale = app(\App\I18n\Localization::class)->locale();
        }
        $customer->setMarketingConsent(true, $source);
        $customer->save();

        return ! $was;
    }

    /** The popup's discount code, if it still works. */
    public function popupCode(Store $store): ?string
    {
        $code = self::popup($store)['discount_code'];
        $discount = $code ? Discount::where('code', $code)->first() : null;

        return $discount && $discount->status() === 'active' ? $discount->code : null;
    }
}
