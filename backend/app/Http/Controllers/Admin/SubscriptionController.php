<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomerSubscription;
use App\Subscriptions\Plans;
use App\Subscriptions\Subscriptions;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Orders › Subscriptions: every subscribe-and-save subscription, its renewals, and actions. */
class SubscriptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $page = CustomerSubscription::query()
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('q'), fn ($q, $t) => $q->where('email', 'like', '%'.addcslashes($t, '%_\\').'%'))
            ->latest('id')->paginate(20);
        $page->getCollection()->transform(fn ($s) => $this->row($s));

        $active = CustomerSubscription::where('status', 'active')->get();
        $monthly = 0;
        foreach ($active as $s) {
            $monthly += $this->value($s) * $this->perMonth($s->interval);
        }

        return response()->json([...$page->toArray(),
            'counts' => CustomerSubscription::selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status'),
            'monthly_value' => Money::format((int) round($monthly)),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $s = CustomerSubscription::findOrFail($id);
        $orders = \App\Models\Order::where('subscription_id', $s->id)->orWhere('id', $s->origin_order_id)->latest('id')->limit(20)
            ->get(['id', 'number', 'total', 'currency', 'financial_status', 'fulfillment_status', 'created_at']);

        return response()->json(['data' => [...$this->row($s), 'items' => $s->items, 'shipping_address' => $s->shipping_address,
            'last_error' => $s->last_error, 'failed_attempts' => $s->failed_attempts,
            'orders' => $orders->map(fn ($o) => ['id' => $o->id, 'name' => $o->name(), 'total' => Money::format($o->total), 'currency' => $o->currency,
                'financial_status' => $o->financial_status, 'created_at' => $o->created_at])]]);
    }

    public function update(Request $request, int $id, Subscriptions $subscriptions): JsonResponse
    {
        $s = CustomerSubscription::findOrFail($id);
        $action = $request->validate(['action' => ['required', Rule::in(['skip', 'pause', 'resume', 'cancel', 'renew_now'])]])['action'];
        abort_if($s->status === 'cancelled', 422, __('订阅已取消'));
        match ($action) {
            'skip' => $subscriptions->skip($s),
            'pause' => $subscriptions->pause($s),
            'resume' => $subscriptions->resume($s),
            'cancel' => $subscriptions->cancel($s),
            'renew_now' => $subscriptions->renew($s),
        };

        return $this->show($s->id);
    }

    private function row(CustomerSubscription $s): array
    {
        return [
            'id' => $s->id, 'email' => $s->email, 'customer_id' => $s->customer_id, 'status' => $s->status,
            'interval' => $s->interval, 'interval_label' => Plans::label($s->interval), 'discount' => $s->discount,
            'items_summary' => collect($s->items)->map(fn ($i) => $i['title'].' × '.$i['quantity'])->implode('、'),
            'value' => Money::format($this->value($s)), 'currency' => $s->currency, 'payment_gateway' => $s->payment_gateway,
            'next_billing_at' => $s->next_billing_at, 'orders_count' => $s->orders_count, 'created_at' => $s->created_at,
        ];
    }

    /** Value of one delivery at today's prices (before tax/shipping). */
    private function value(CustomerSubscription $s): int
    {
        $prices = \App\Models\ProductVariant::whereIn('id', array_column($s->items, 'variant_id'))->get()->mapWithKeys(fn ($v) => [$v->id => (int) $v->getRawOriginal('price')]);
        $sum = 0;
        foreach ($s->items as $i) {
            $sum += (int) round(($prices[$i['variant_id']] ?? 0) * (100 - $s->discount) / 100) * $i['quantity'];
        }

        return $sum;
    }

    private function perMonth(string $interval): float
    {
        [$unit, $n] = explode(':', $interval);

        return $unit === 'week' ? 52 / 12 / (int) $n : 1 / (int) $n;
    }
}
