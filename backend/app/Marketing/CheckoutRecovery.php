<?php

namespace App\Marketing;

use App\I18n\Localization;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Store;
use App\Notifications\RecoveryMail;
use Illuminate\Support\Facades\Mail;

/**
 * Abandoned checkout recovery. A shopper who reaches checkout and enters an email but doesn't
 * pay gets one reminder email after the store's delay, with a link that restores their cart
 * (and optionally a discount code). Settings live in stores.settings['recovery'].
 */
class CheckoutRecovery
{
    public const DEFAULTS = ['enabled' => true, 'delay_hours' => 4, 'discount_code' => null];

    public const DELAYS = [1, 4, 10, 24];

    /** Don't email about carts older than this. */
    public const MAX_AGE_DAYS = 7;

    public static function settings(Store $store): array
    {
        $saved = $store->settings['recovery'] ?? [];
        $s = array_merge(self::DEFAULTS, array_intersect_key($saved, self::DEFAULTS));
        $s['delay_hours'] = in_array((int) $s['delay_hours'], self::DELAYS, true) ? (int) $s['delay_hours'] : self::DEFAULTS['delay_hours'];

        return $s;
    }

    public static function save(Store $store, array $data): void
    {
        $settings = $store->settings ?? [];
        $settings['recovery'] = [
            'enabled' => (bool) $data['enabled'],
            'delay_hours' => (int) $data['delay_hours'],
            'discount_code' => filled($data['discount_code'] ?? null) ? strtoupper(trim($data['discount_code'])) : null,
        ];
        $store->settings = $settings;
        $store->save();
    }

    /** Send every reminder that is due. Returns how many were sent. */
    public function run(): int
    {
        $sent = 0;
        Cart::withoutGlobalScope('store')
            ->whereNull('recovery_sent_at')->whereNotNull('email')->whereNotNull('checkout_started_at')
            ->where('updated_at', '<=', now()->subHours(min(self::DELAYS)))
            ->where('updated_at', '>=', now()->subDays(self::MAX_AGE_DAYS))
            ->whereHas('items')
            ->orderBy('id')
            ->chunkById(200, function ($carts) use (&$sent) {
                foreach ($carts as $cart) {
                    $sent += $this->sendIfDue($cart) ? 1 : 0;
                }
            });

        return $sent;
    }

    public function sendIfDue(Cart $cart): bool
    {
        $store = Store::find($cart->store_id);
        if (! $store || ! app(\App\Billing\Billing::class)->isOpen($store)) {
            return false;
        }
        $s = self::settings($store);
        if (! $s['enabled'] || $cart->updated_at->gt(now()->subHours($s['delay_hours']))) {
            return false;
        }

        // Claim it first so two overlapping runs can't both send
        $claimed = Cart::withoutGlobalScope('store')->whereKey($cart->id)->whereNull('recovery_sent_at')
            ->update(['recovery_sent_at' => now(), 'updated_at' => $cart->updated_at]);
        if (! $claimed) {
            return false;
        }

        // Already bought since (e.g. on another device): nothing to recover
        $ordered = Order::withoutGlobalScope('store')->where('store_id', $store->id)
            ->where('email', $cart->email)->where('created_at', '>=', $cart->checkout_started_at)->exists();
        if ($ordered) {
            return false;
        }

        $locale = $cart->locale ?: Localization::storeDefault($store);
        app(\App\Promotions\Promotions::class)->enableFor($store);   // sale prices, as on the storefront
        app(Localization::class)->within($store, $locale, function () use ($cart, $store, $s) {
            $cart = Cart::withoutGlobalScope('store')->with('items.variant.product', 'items.variant.image')->find($cart->id);
            Mail::to($cart->email)->send(new RecoveryMail($cart, $store, $s['discount_code']));
        });

        return true;
    }

    /** Recovery-email results over the last $days days, for the admin. */
    public function stats(Store $store, int $days = 30): array
    {
        $since = now()->subDays($days);
        $orders = Order::withoutGlobalScope('store')->where('store_id', $store->id)->where('recovered', true)->where('created_at', '>=', $since);

        return [
            'sent' => Cart::withoutGlobalScope('store')->where('store_id', $store->id)->where('recovery_sent_at', '>=', $since)->count()
                + (clone $orders)->count(),   // recovered carts are deleted once ordered
            'recovered_orders' => (clone $orders)->count(),
            'recovered_revenue' => \App\Support\Money::format((int) (clone $orders)->sum('total')),
        ];
    }
}
