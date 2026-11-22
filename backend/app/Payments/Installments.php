<?php

namespace App\Payments;

use App\Markets\MarketContext;
use App\Markets\MarketSettings;

/**
 * Card installments (meses sin intereses in Mexico, parcelamento in Brazil), switched on per market
 * (markets.settings.installments). Stripe Checkout then lets the buyer pick a plan their card
 * supports; the product page shows "or N installments of X". Stripe only offers card installments
 * for these currencies, and the Stripe account must be in that country.
 */
final class Installments
{
    public const CURRENCIES = ['MXN', 'BRL'];

    public const MONTHS = [3, 6, 9, 12, 18, 24];

    /** The current market's installment settings when they apply, else null. */
    public static function settings(): ?array
    {
        $ctx = app(MarketContext::class);
        $market = $ctx->market();
        if (! $market || ! in_array($market->currency, self::CURRENCIES, true)) {
            return null;
        }
        $s = MarketSettings::of($market)['installments'];

        return $s['enabled'] ? $s : null;
    }

    /**
     * What to tell the shopper for a price (minor units, market currency), or null.
     *
     * @return array{count: int, each: int, interest_free: bool}|null
     */
    public static function offer(int $price): ?array
    {
        $s = self::settings();
        if (! $s || $price <= 0 || $price < $s['min']) {
            return null;
        }

        return ['count' => $s['months'], 'each' => (int) ceil($price / $s['months']), 'interest_free' => $s['interest_free']];
    }

    /** Should Stripe Checkout offer installments for this order? */
    public static function forOrder(\App\Models\Order $order): bool
    {
        if (! in_array($order->currency, self::CURRENCIES, true) || ! $order->market_id) {
            return false;
        }
        $market = \App\Models\Market::withoutGlobalScope('store')->find($order->market_id);
        $s = $market ? MarketSettings::of($market)['installments'] : null;

        return $s && $s['enabled'] && $order->total >= $s['min'];
    }
}
