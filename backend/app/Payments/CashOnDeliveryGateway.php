<?php

namespace App\Payments;

use App\Markets\MarketSettings;
use App\Models\Order;

/**
 * Cash on delivery, switched on per market (markets.settings.cod): the buyer pays the courier;
 * the order stays pending until the merchant marks it paid. An optional fee is added at checkout
 * and an optional ceiling keeps large orders on prepaid methods. Delivery must be to one of the
 * market's countries, with a phone number for the courier.
 */
class CashOnDeliveryGateway implements PaymentGateway
{
    public function key(): string
    {
        return 'cod';
    }

    public function label(): string
    {
        return __('Cash on delivery');
    }

    /** Offered per market (GatewayRegistry::available), never through the store's gateway switches. */
    public function isAvailable(): bool
    {
        return true;
    }

    /** COD settings of the market the shopper is in, when it offers COD. */
    public static function settings(): ?array
    {
        $market = app(\App\Markets\MarketContext::class)->market();
        $cod = $market ? MarketSettings::of($market)['cod'] : null;

        return $cod && $cod['enabled'] ? [...$cod, 'countries' => (array) $market->countries] : null;
    }

    /** Why this order can't be paid on delivery, or null when it can. */
    public static function refusal(int $total, string $country, ?string $phone, bool $physical): ?string
    {
        $cod = self::settings();
        if (! $cod) {
            return __('Cash on delivery isn’t available here.');
        }
        if (! $physical) {
            return __('Cash on delivery needs something to deliver.');
        }
        if (! in_array($country, $cod['countries'], true)) {
            return __('Cash on delivery is only available for local addresses.');
        }
        if ($cod['max'] > 0 && $total > $cod['max']) {
            return __('Cash on delivery is available for orders up to :amount.', ['amount' => \App\Support\Money::display($cod['max'], app(\App\Markets\MarketContext::class)->currency())]);
        }
        if (trim((string) $phone) === '') {
            return __('Please add a phone number so the courier can reach you.');
        }

        return null;
    }

    public function start(Order $order, string $storefrontUrl): ?string
    {
        $order->log('cod', __('货到付款：送达时向买家收取 :amount', ['amount' => \App\Support\Money::display((int) $order->total, $order->currency)]));

        return null;
    }

    public function refundsOnline(): bool
    {
        return false;
    }

    public function refund(Order $order, int $amount, string $idempotencyKey): ?string
    {
        return null;
    }
}
