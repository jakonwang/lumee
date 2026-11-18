<?php

namespace App\Billing;

use App\Models\PlatformFee;
use App\Models\PlatformInvoice;
use App\Models\Store;
use App\Models\Subscription;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Scheduled billing work. Stripe renews its own subscriptions; this handles everything else. */
class BillingRunner
{
    public function __construct(private Billing $billing, private FeeLedger $fees) {}

    /** Daily: renew/expire non-Stripe subscriptions and remind trials that end within 3 days. */
    public function daily(): array
    {
        $stats = ['renewed' => 0, 'ended' => 0, 'reminded' => 0];

        $due = Subscription::withoutGlobalScope('store')->whereIn('provider', ['test', 'manual'])
            ->where('status', 'active')->whereNotNull('current_period_end')->where('current_period_end', '<=', now())->get();
        foreach ($due as $sub) {
            $store = Store::find($sub->store_id);
            if ($sub->provider === 'test' && ! $sub->cancel_at_period_end) {
                $start = $sub->current_period_end;
                $this->billing->upsert($store, ['current_period_end' => $this->billing->periodEnd($start, $sub->interval)]);
                $this->billing->recordSubscriptionInvoice($store, $sub->fresh(), $start, $sub->fresh()->current_period_end, null);
                $stats['renewed']++;
            } else {
                $this->billing->upsert($store, ['status' => 'canceled', 'ended_at' => now()]);
                $stats['ended']++;
            }
        }

        $ending = Store::where('status', 'active')->whereNull('trial_reminded_at')
            ->whereBetween('trial_ends_at', [now(), now()->addDays(3)])
            ->whereDoesntHave('subscription', fn ($q) => $q->where('status', '!=', 'canceled'))->get();
        foreach ($ending as $store) {
            BillingMailer::notify($store, 'trial_ending');
            $store->forceFill(['trial_reminded_at' => now()])->save();
            $stats['reminded']++;
        }

        return $stats;
    }

    /**
     * Monthly: turn each store's unbilled transaction fees into a platform invoice (USD).
     * Stripe subscribers: added to their next Stripe invoice. Test mode: marked paid.
     * Ops-granted / no subscription: left open for ops to collect. Net credits carry over.
     */
    public function billFees(): array
    {
        $stats = ['invoiced' => 0, 'skipped' => 0];
        $storeIds = PlatformFee::withoutGlobalScope('store')->whereNull('invoice_id')->distinct()->pluck('store_id');

        foreach (Store::whereIn('id', $storeIds)->get() as $store) {
            $pending = $this->fees->pending($store);
            if ($pending['usd'] === null || $pending['usd'] <= 0) {
                $stats['skipped']++;   // missing exchange rate, or refunds outweigh fees this month

                continue;
            }
            try {
                $this->invoiceFees($store, $pending);
                $stats['invoiced']++;
            } catch (Throwable $e) {
                report($e);
                $stats['skipped']++;
            }
        }

        return $stats;
    }

    private function invoiceFees(Store $store, array $pending): PlatformInvoice
    {
        $rows = PlatformFee::withoutGlobalScope('store')->whereIn('id', $pending['ids'])->get();
        $sub = $this->billing->subscription($store);
        $period = [$rows->min('created_at'), $rows->max('created_at')];
        $lines = collect($pending['by_currency'])->map(fn ($amount, $cur) => [
            'description' => __('交易佣金（:p0 订单）', ['p0' => $cur]),
            'amount' => Money::format($amount),
            'currency' => $cur,
            'orders' => $rows->where('currency', $cur)->where('type', 'fee')->count(),
        ])->values()->all();
        $description = __('交易佣金 ').$period[0]->format('Y-m-d').__(' 至 ').$period[1]->format('Y-m-d');

        return DB::transaction(function () use ($store, $sub, $pending, $lines, $description, $period) {
            $invoice = PlatformInvoice::withoutGlobalScope('store')->create([
                'store_id' => $store->id,
                'number' => PlatformInvoice::nextNumber(),
                'kind' => 'fees',
                'description' => $description,
                'amount' => $pending['usd'],
                'status' => 'open',
                'period_start' => $period[0],
                'period_end' => $period[1],
                'lines' => $lines,
            ]);
            PlatformFee::withoutGlobalScope('store')->whereIn('id', $pending['ids'])->update(['invoice_id' => $invoice->id]);

            if ($sub?->provider === 'stripe' && $sub->stripe_customer_id && $sub->status !== 'canceled') {
                $itemId = app(StripeBilling::class)->addInvoiceItem($sub, $pending['usd'], "Lumée {$description}", 'fees-'.$invoice->number);
                $invoice->update(['provider_ref' => $itemId]);   // marked paid by the invoice.paid webhook
            } elseif ($sub?->provider === 'test') {
                $invoice->update(['status' => 'paid', 'paid_at' => now()]);
            }

            return $invoice;
        });
    }
}
