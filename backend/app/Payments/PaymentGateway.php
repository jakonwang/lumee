<?php

namespace App\Payments;

use App\Models\Order;

interface PaymentGateway
{
    /** Machine name stored on the order, e.g. "stripe". */
    public function key(): string;

    /** Label shown to buyers at checkout. */
    public function label(): string;

    public function isAvailable(): bool;

    /**
     * Start payment for a freshly placed order.
     * Return a URL to redirect the buyer to (hosted payment page), or null to go
     * straight to the order status page.
     */
    public function start(Order $order, string $storefrontUrl): ?string;

    /** True when refund() actually returns money to the buyer (false = merchant refunds offline). */
    public function refundsOnline(): bool;

    /**
     * Refund $amount (minor units) of a paid order. Returns the gateway's refund id, or null
     * when nothing is sent anywhere. Throws RuntimeException with a merchant-readable message on failure.
     */
    public function refund(Order $order, int $amount, string $idempotencyKey): ?string;
}
