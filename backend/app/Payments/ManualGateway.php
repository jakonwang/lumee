<?php

namespace App\Payments;

use App\Models\Order;

/** Bank transfer / cash on delivery: order stays pending until the merchant marks it paid. */
class ManualGateway implements PaymentGateway
{
    public function key(): string
    {
        return 'manual';
    }

    /** The store's own name for it ("Bank transfer", "Cash on delivery"…). */
    public function label(): string
    {
        $current = app(\App\Tenancy\CurrentStore::class);

        return $current->check() ? PaymentSettings::for($current->getOrFail())->manualLabel() : PaymentSettings::DEFAULTS['manual']['label'];
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function start(Order $order, string $storefrontUrl): ?string
    {
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
