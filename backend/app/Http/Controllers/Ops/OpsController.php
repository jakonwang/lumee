<?php

namespace App\Http\Controllers\Ops;

use App\Billing\Billing;
use App\Billing\FeeLedger;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PlatformInvoice;
use App\Models\Product;
use App\Models\Store;
use App\Models\Subscription;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Lumée ops console: platform-wide numbers, every store's plan/state, and support actions.
 * No CurrentStore here, so store-scoped models are queried with explicit store ids.
 */
class OpsController extends Controller
{
    public function __construct(private Billing $billing) {}

    public function overview(): JsonResponse
    {
        $stores = Store::with('subscription')->get();
        $states = $stores->countBy(fn (Store $s) => $this->billing->state($s, $s->subscription));

        // MRR: paying subscriptions (Stripe / test), yearly plans counted per month; ops grants are free
        $mrr = $stores->sum(function (Store $s) {
            $sub = $s->subscription;
            $state = $this->billing->state($s, $sub);

            return in_array($state, ['active', 'past_due'], true) && in_array($sub?->provider, ['stripe', 'test'], true)
                ? $this->billing->monthlyPrice($sub->plan, $sub->interval) : 0;
        });

        $paid = PlatformInvoice::withoutGlobalScope('store')->where('status', 'paid');
        $fees = app(FeeLedger::class);
        $pendingFees = $stores->sum(fn (Store $s) => $fees->pending($s)['usd'] ?? 0);

        $since = now()->subDays(29)->startOfDay();
        $signups = Store::where('created_at', '>=', $since)->get(['created_at'])
            ->groupBy(fn ($s) => $s->created_at->toDateString())->map->count();
        $series = [];
        for ($d = $since->copy(); $d->lte(now()); $d->addDay()) {
            $series[] = ['date' => $d->toDateString(), 'signups' => $signups[$d->toDateString()] ?? 0];
        }

        return response()->json([
            'stores' => $stores->count(),
            'states' => collect(['trialing', 'active', 'past_due', 'expired', 'suspended'])->mapWithKeys(fn ($k) => [$k => $states[$k] ?? 0]),
            'mrr' => Money::format($mrr),
            'revenue_30d' => Money::format((int) (clone $paid)->where('paid_at', '>=', now()->subDays(30))->sum('amount')),
            'revenue_total' => Money::format((int) (clone $paid)->sum('amount')),
            'open_invoices' => Money::format((int) PlatformInvoice::withoutGlobalScope('store')->where('status', 'open')->sum('amount')),
            'pending_fees' => Money::format($pendingFees),
            'trials_ending_7d' => $stores->filter(fn ($s) => $this->billing->state($s, $s->subscription) === 'trialing'
                && $s->trial_ends_at->lte(now()->addDays(7)))->count(),
            'signups' => $series,
            'plans' => collect($this->billing->plans())->map(fn ($p, $k) => [
                'key' => $k, 'name' => $p['name'],
                'stores' => $stores->filter(fn ($s) => $s->subscription?->plan === $k && in_array($this->billing->state($s, $s->subscription), ['active', 'past_due'], true))->count(),
            ])->values(),
        ]);
    }

    public function stores(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));
        $state = $request->query('state');
        $stores = Store::with('subscription', 'owner:id,name,email', 'domains')
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.addcslashes($q, '%_\\').'%';
                $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('handle', 'like', $like)
                    ->orWhereHas('owner', fn ($o) => $o->where('email', 'like', $like)));
            })
            ->latest('id')->get()
            ->map(fn (Store $s) => $this->row($s))
            ->when($state, fn (Collection $c) => $c->where('state', $state))
            ->values();

        $page = max(1, (int) $request->query('page', 1));
        $per = 20;

        return response()->json([
            'data' => $stores->forPage($page, $per)->values(),
            'total' => $stores->count(),
            'per_page' => $per,
            'current_page' => $page,
        ]);
    }

    public function store(int $id): JsonResponse
    {
        $store = Store::with('subscription', 'owner:id,name,email', 'domains')->findOrFail($id);
        $orders = Order::withoutGlobalScope('store')->where('store_id', $id);
        $paid = (clone $orders)->whereIn('financial_status', Order::PAID_STATUSES);
        $pending = app(FeeLedger::class)->pending($store);

        return response()->json([
            ...$this->row($store),
            'suspended_reason' => $store->suspended_reason,
            'subscription' => $store->subscription,
            'limits' => $this->billing->limits($store),
            'members' => $store->users()->count(),
            'products' => Product::withoutGlobalScope('store')->where('store_id', $id)->count(),
            'orders' => (clone $orders)->count(),
            'gmv_total' => Money::format((int) (clone $paid)->sum(DB::raw('(total - refunded_total) / exchange_rate'))),
            'fees_pending_usd' => $pending['usd'] !== null ? Money::format($pending['usd']) : null,
            'domains' => $store->domains->map->only('host', 'type', 'is_primary', 'verified_at'),
            'invoices' => PlatformInvoice::withoutGlobalScope('store')->where('store_id', $id)->latest('id')->limit(50)->get()->map->toApi(),
            'plans' => collect($this->billing->plans())->map(fn ($p, $k) => ['key' => $k, 'name' => $p['name']])->values(),
        ]);
    }

    public function extendTrial(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:90']]);
        $store = Store::findOrFail($id);
        $from = $store->trial_ends_at?->isFuture() ? $store->trial_ends_at : now();
        $store->forceFill(['trial_ends_at' => $from->copy()->addDays($data['days']), 'trial_reminded_at' => null])->save();

        return $this->store($id);
    }

    /** Give a store a plan for free (partners, support goodwill). */
    public function grant(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', Rule::in(array_keys($this->billing->plans()))],
            'until' => ['nullable', 'date', 'after:today'],
            'note' => ['required', 'string', 'max:255'],
        ], ['note.required' => __('请填写赠送原因，便于以后核对')]);
        $store = Store::findOrFail($id);
        $this->billing->grant($store, $data['plan'], isset($data['until']) ? Carbon::parse($data['until'])->endOfDay() : null,
            $data['note'].__('（').request()->user()->email.' '.now()->toDateString().__('）'));

        return $this->store($id);
    }

    public function suspend(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.required' => __('请填写暂停原因（商家会看到）')]);
        Store::findOrFail($id)->forceFill(['status' => 'suspended', 'suspended_reason' => $data['reason']])->save();

        return $this->store($id);
    }

    public function unsuspend(int $id): JsonResponse
    {
        $store = Store::findOrFail($id);
        if ($store->status !== 'suspended') {
            throw ValidationException::withMessages(['store' => __('店铺没有被暂停')]);
        }
        $store->forceFill(['status' => 'active', 'suspended_reason' => null])->save();

        return $this->store($id);
    }

    public function invoices(Request $request): JsonResponse
    {
        $page = PlatformInvoice::withoutGlobalScope('store')->with('store:id,name,handle')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->latest('id')->paginate(30);
        $page->getCollection()->transform(fn (PlatformInvoice $i) => [...$i->toApi(), 'store' => $i->store?->only('id', 'name', 'handle')]);

        return response()->json($page);
    }

    /** Fees from stores without a Stripe subscription are collected offline; ops records the payment. */
    public function markInvoicePaid(int $id): JsonResponse
    {
        $invoice = PlatformInvoice::withoutGlobalScope('store')->findOrFail($id);
        if ($invoice->status !== 'open') {
            throw ValidationException::withMessages(['invoice' => __('只有未付款的账单可以标记为已付款')]);
        }
        $invoice->update(['status' => 'paid', 'paid_at' => now()]);

        return response()->json(['data' => $invoice->toApi()]);
    }

    private function row(Store $s): array
    {
        $sub = $s->subscription;
        $state = $this->billing->state($s, $sub);
        $gmv30 = Order::withoutGlobalScope('store')->where('store_id', $s->id)->whereIn('financial_status', Order::PAID_STATUSES)
            ->where('paid_at', '>=', now()->subDays(30))->sum(DB::raw('(total - refunded_total) / exchange_rate'));

        return [
            'id' => $s->id,
            'name' => $s->name,
            'handle' => $s->handle,
            'owner' => $s->owner?->only('name', 'email'),
            'currency' => $s->currency,
            'state' => $state,
            'plan' => $sub && $sub->status !== 'canceled' ? $sub->plan : null,
            'plan_name' => $sub && $sub->status !== 'canceled' ? ($this->billing->plan($sub->plan)['name'] ?? $sub->plan) : null,
            'interval' => $sub?->interval,
            'provider' => $sub?->provider,
            'trial_ends_at' => $s->trial_ends_at,
            'current_period_end' => $sub?->current_period_end,
            'gmv_30d' => Money::format((int) $gmv30),
            'storefront_url' => $s->storefrontUrl(),
            'created_at' => $s->created_at,
        ];
    }
}
