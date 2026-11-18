<?php

namespace App\Payments;

use App\Models\Order;

/** Local development only: instantly marks the order paid, so the full flow can be tried without Stripe keys. */
class TestGateway implements PaymentGateway
{
    public function key(): string
    {
        return 'test';
    }

    public function label(): string
    {
        return __('Test payment (dev only)');
    }

    public function isAvailable(): bool
    {
        return (bool) config('lumee.payments.test_gateway');
    }

    public function start(Order $order, string $storefrontUrl): ?string
    {
        $order->markPaid('test_'.$order->token);

        return null;
    }

    public function refundsOnline(): bool
    {
        return false;
    }

    public function refund(Order $order, int $amount, string $idempotencyKey): ?string
    {
        return null;   // money goes back outside Lumée (bank transfer etc.)
    }
}
