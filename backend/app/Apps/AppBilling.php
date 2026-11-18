<?php

namespace App\Apps;

use App\Billing\Billing;
use App\Billing\StripeBilling;
use App\Models\AppCharge;
use App\Models\AppInstallation;
use App\Models\PlatformInvoice;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * App billing. An app creates a charge over the API; the merchant approves it in the admin; Lumée
 * bills it on the store's platform invoice (Stripe subscribers: added to their next Stripe invoice)
 * and keeps 20%, the developer earns 80%.
 *
 *  - Recurring charges (every 30 days) start after the free trial, which a store gets once per app.
 *  - Approving a new recurring charge replaces the app's current one (a plan change).
 *  - Test charges (for development stores) are never billed.
 *  - Uninstalling the app cancels its charges.
 */
class AppBilling
{
    public const PERIOD_DAYS = 30;

    public function create(AppInstallation $install, array $data): AppCharge
    {
        return AppCharge::create([
            'app_id' => $install->app_id, 'store_id' => $install->store_id, 'app_installation_id' => $install->id,
            'token' => Str::random(40), 'name' => $data['name'], 'price' => (int) round($data['price'] * 100),
            'interval' => $data['interval'] ?? 'every_30_days', 'trial_days' => (int) ($data['trial_days'] ?? 0),
            'return_url' => $data['return_url'], 'test' => (bool) ($data['test'] ?? false), 'status' => 'pending',
        ]);
    }

    public function approve(AppCharge $charge): AppCharge
    {
        if ($charge->status !== 'pending') {
            throw ValidationException::withMessages(['charge' => __('这笔收费已经处理过了')]);
        }

        return DB::transaction(function () use ($charge) {
            if ($charge->interval === 'one_time') {
                $charge->forceFill(['status' => 'active', 'activated_at' => now()])->save();
                $this->bill($charge, now(), null);

                return $charge;
            }
            $usedTrial = AppCharge::where('app_id', $charge->app_id)->where('store_id', $charge->store_id)
                ->where('interval', 'every_30_days')->whereNotNull('activated_at')->exists();
            $trialEnds = $charge->trial_days > 0 && ! $usedTrial ? now()->addDays($charge->trial_days) : null;
            // A new plan replaces the current one
            AppCharge::where('app_id', $charge->app_id)->where('store_id', $charge->store_id)->where('interval', 'every_30_days')
                ->where('status', 'active')->whereKeyNot($charge->id)->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            $charge->forceFill(['status' => 'active', 'activated_at' => now(), 'trial_ends_at' => $trialEnds, 'billing_on' => $trialEnds ?? now()])->save();
            if (! $trialEnds) {
                $this->billPeriod($charge);
            }

            return $charge;
        });
    }

    public function decline(AppCharge $charge): void
    {
        if ($charge->status === 'pending') {
            $charge->forceFill(['status' => 'declined'])->save();
        }
    }

    public function cancel(AppCharge $charge): void
    {
        // A paid one-time charge can't be undone; pending charges and running plans can
        if ($charge->status === 'pending' || ($charge->status === 'active' && $charge->interval === 'every_30_days')) {
            $charge->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
        }
    }

    public function cancelForInstallation(AppInstallation $install): void
    {
        AppCharge::where('app_id', $install->app_id)->where('store_id', $install->store_id)
            ->where(fn ($q) => $q->where('status', 'pending')->orWhere(fn ($w) => $w->where('status', 'active')->where('interval', 'every_30_days')))
            ->update(['status' => 'cancelled', 'cancelled_at' => now()]);
    }

    /** Scheduler: bill every recurring charge whose period has come round. */
    public function billDue(): int
    {
        $n = 0;
        $due = AppCharge::where('status', 'active')->where('interval', 'every_30_days')->where('billing_on', '<=', now())->limit(500)->get();
        foreach ($due as $charge) {
            try {
                $this->billPeriod($charge);
                $n++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $n;
    }

    private function billPeriod(AppCharge $charge): void
    {
        $start = $charge->billing_on ?? now();
        $end = $start->copy()->addDays(self::PERIOD_DAYS);
        $this->bill($charge, $start, $end);
        $charge->forceFill(['billing_on' => $end])->save();
    }

    private function bill(AppCharge $charge, $start, $end): void
    {
        if ($charge->test || $charge->price <= 0) {
            return;   // development charges are free
        }
        $store = Store::find($charge->store_id);
        $app = $charge->app;
        $description = __('应用「:p0」：:p1', ['p0' => $app?->name, 'p1' => $charge->name]);
        $invoice = app(\App\Billing\MarketplaceInvoices::class)->charge($store, $charge->price, 'apps', $description, $start, $end);
        DB::table('app_charge_bills')->insert([
            'app_charge_id' => $charge->id, 'app_id' => $charge->app_id, 'store_id' => $store->id, 'platform_invoice_id' => $invoice->id,
            'amount' => $charge->price, 'developer_share' => \App\Billing\MarketplaceInvoices::developerShare($charge->price),
            'period_start' => $start, 'period_end' => $end, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
