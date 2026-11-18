<?php

namespace App\Payments;

use App\B2B\B2B;
use App\Models\Order;

/**
 * Pay on account (Net 15/30/60…): for companies with payment terms. The order is placed now,
 * ships like a paid order would be prepared, and is due N days later; the merchant marks it paid.
 */
class TermsGateway implements PaymentGateway
{
    public function key(): string
    {
        return 'terms';
    }

    public function label(): string
    {
        $days = app(B2B::class)->termsDays();

        return $days ? __('Pay on account (Net :days)', ['days' => $days]) : __('Pay on account');
    }

    /** Offered per buyer (CheckoutController), never through the store's gateway switches. */
    public function isAvailable(): bool
    {
        return true;
    }

    public function start(Order $order, string $storefrontUrl): ?string
    {
        $days = app(B2B::class)->termsDays();
        $order->forceFill(['payment_due_at' => now()->addDays(max(1, $days))])->save();
        $order->log('terms', __('账期付款：:p0 天内付款（到期 :p1）', ['p0' => $days, 'p1' => $order->payment_due_at->toDateString()]));

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
