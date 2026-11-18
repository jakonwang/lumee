<?php

namespace App\Billing;

use App\Models\PlatformInvoice;
use App\Models\Store;

/**
 * Platform invoices for marketplace purchases (app charges, paid themes). Same collection path as
 * transaction fees: Stripe subscribers get it on their next Stripe invoice; test mode is marked paid;
 * otherwise it stays open for ops to collect. Developers are paid only for invoices marked paid.
 */
class MarketplaceInvoices
{
    public const DEVELOPER_SHARE_BP = 8000;

    public static function developerShare(int $amount): int
    {
        return (int) floor($amount * self::DEVELOPER_SHARE_BP / 10000);
    }

    public function charge(Store $store, int $usdCents, string $kind, string $description, $start = null, $end = null): PlatformInvoice
    {
        $invoice = PlatformInvoice::withoutGlobalScope('store')->create([
            'store_id' => $store->id, 'number' => PlatformInvoice::nextNumber(), 'kind' => $kind, 'description' => $description,
            'amount' => $usdCents, 'status' => 'open', 'period_start' => $start ?? now(), 'period_end' => $end,
            'lines' => [['description' => $description, 'amount' => number_format($usdCents / 100, 2, '.', ''), 'currency' => 'USD']],
        ]);
        $sub = app(Billing::class)->subscription($store);
        if ($sub?->provider === 'stripe' && $sub->stripe_customer_id && $sub->status !== 'canceled') {
            $itemId = app(StripeBilling::class)->addInvoiceItem($sub, $usdCents, "Lumée {$description}", $kind.'-'.$invoice->number);
            $invoice->update(['provider_ref' => $itemId]);   // marked paid by the invoice.paid webhook
        } elseif ($sub?->provider === 'test') {
            $invoice->update(['status' => 'paid', 'paid_at' => now()]);
        }

        return $invoice;
    }
}
