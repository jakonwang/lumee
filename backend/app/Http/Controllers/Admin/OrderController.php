<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Notifications\OrderNotifier;
use App\Services\OrderService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    private const DETAIL = ['items', 'notifications', 'events.user:id,name', 'refunds.user:id,name'];

    public function index(Request $request): AnonymousResourceCollection
    {
        return OrderResource::collection(
            $this->filtered($request)->withCount('items')->latest('id')->paginate(min((int) $request->query('per_page', 20), 100))
        );
    }

    /** CSV of the orders matching the list's current tab / search (one row per order). */
    public function export(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $query = $this->filtered($request)->with('items');
        $rows = function () use ($query) {
            $n = 0;
            foreach ($query->lazyById(500, 'id') as $o) {
                if (++$n > self::EXPORT_MAX) {
                    break;
                }
                $a = $o->shipping_address ?? [];
                yield [
                    $o->name(), $o->created_at?->toDateTimeString(), $o->email,
                    trim(($a['first_name'] ?? '').' '.($a['last_name'] ?? '')),
                    $o->financial_status, $o->fulfillment_status, $o->currency,
                    Money::format($o->subtotal), Money::format($o->shipping_total), $o->discount_code, Money::format($o->discount_total),
                    Money::format($o->tax_total), Money::format($o->total), Money::format($o->refunded_total),
                    $o->payment_gateway, $o->shipping_method['name'] ?? null,
                    $a['address1'] ?? null, $a['address2'] ?? null, $a['city'] ?? null, $a['province'] ?? null, $a['zip'] ?? null, $a['country'] ?? null, $a['phone'] ?? null,
                    $o->tracking_company, $o->tracking_number, $o->cancelled_at?->toDateTimeString(),
                    $o->items->map(fn ($i) => $i->title.($i->variant_title ? " ({$i->variant_title})" : '').' x'.$i->quantity.($i->sku ? " [{$i->sku}]" : ''))->implode('; '),
                    $o->note,
                ];
            }
        };

        return \App\Support\Csv::download('orders-'.now()->format('Ymd-His').'.csv', [
            'Name', 'Created at', 'Email', 'Customer', 'Financial Status', 'Fulfillment Status', 'Currency',
            'Subtotal', 'Shipping', 'Discount Code', 'Discount Amount', 'Taxes', 'Total', 'Refunded Amount',
            'Payment Method', 'Shipping Method', 'Shipping Address1', 'Shipping Address2', 'Shipping City', 'Shipping Province',
            'Shipping Zip', 'Shipping Country', 'Shipping Phone', 'Tracking Company', 'Tracking Number', 'Cancelled at', 'Line Items', 'Notes',
        ], $rows());
    }

    private const EXPORT_MAX = 50000;

    /** Tabs (status), search and status filters shared by the list and the export. */
    private function filtered(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $status = $request->query('status');

        return Order::query()
            ->when($request->query('financial_status'), fn ($q, $s) => $q->where('financial_status', $s))
            ->when($request->query('fulfillment_status'), fn ($q, $s) => $q->where('fulfillment_status', $s))
            // Tabs: open orders exclude cancelled ones; "cancelled" shows only those
            ->when($status === 'open', fn ($q) => $q->whereNull('cancelled_at'))
            ->when($status === 'unpaid', fn ($q) => $q->whereNull('cancelled_at')->where('financial_status', 'pending'))
            ->when($status === 'to_fulfill', fn ($q) => $q->whereNull('cancelled_at')->where('fulfillment_status', 'unfulfilled')
                ->whereIn('financial_status', ['paid', 'partially_refunded']))
            ->when($status === 'fulfilled', fn ($q) => $q->where('fulfillment_status', 'fulfilled'))
            ->when($status === 'cancelled', fn ($q) => $q->whereNotNull('cancelled_at'))
            ->when($status === 'refunded', fn ($q) => $q->whereIn('financial_status', ['partially_refunded', 'refunded']))
            ->when($request->query('customer_id'), fn ($q, $id) => $q->where('customer_id', (int) $id))
            ->when($request->query('from'), fn ($q, $d) => $q->where('created_at', '>=', \Carbon\Carbon::parse($d)->startOfDay()))
            ->when($request->query('to'), fn ($q, $d) => $q->where('created_at', '<=', \Carbon\Carbon::parse($d)->endOfDay()))
            ->when($request->query('q'), function ($q, $term) {
                $q->where(fn ($w) => $w->where('email', 'like', '%'.addcslashes($term, '%_\\').'%')
                    ->orWhere('number', ltrim($term, '#')));
            });
    }

    public function show(Order $order): OrderResource
    {
        return $this->detail($order);
    }

    /** For manual payments (bank transfer / COD) once the merchant has received the money. */
    public function markPaid(Order $order): OrderResource
    {
        if ($order->financial_status !== 'pending' || $order->isCancelled()) {
            throw ValidationException::withMessages(['order' => __('只有待付款的订单可以标记为已付款')]);
        }
        $order->markPaid();

        return $this->detail($order);
    }

    public function fulfill(Request $request, Order $order): OrderResource
    {
        $data = $request->validate([
            'tracking_company' => ['nullable', 'string', 'max:100'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'notify' => ['sometimes', 'boolean'],
        ]);

        if ($order->isCancelled()) {
            throw ValidationException::withMessages(['order' => __('订单已取消，不能发货')]);
        }
        if (! $order->isPaid()) {
            throw ValidationException::withMessages(['order' => __('订单还没有付款，不能发货')]);
        }
        if ($order->fulfillment_status === 'fulfilled') {
            throw ValidationException::withMessages(['order' => __('该订单已发货')]);
        }
        if ($order->risk_hold) {
            throw ValidationException::withMessages(['order' => __('这是高风险订单，已暂停发货。请先核实订单，确认没有问题后再发货。')]);
        }

        $order->update([
            'tracking_company' => $data['tracking_company'] ?? null,
            'tracking_number' => $data['tracking_number'] ?? null,
            'fulfillment_status' => 'fulfilled',
            'fulfilled_at' => now(),
        ]);
        $order->log('fulfilled', __('订单已发货').($order->tracking_number ? __('：').trim($order->tracking_company.' '.$order->tracking_number) : ''));
        app(\App\Shipping\PackageTracking::class)->register($order);
        app(\App\Webhooks\Webhooks::class)->orderEvent($order, 'orders/fulfilled');
        if ($data['notify'] ?? true) {
            app(OrderNotifier::class)->orderFulfilled($order);
        }

        return $this->detail($order);
    }

    /** Update tracking info after shipping (typo fixes, carrier change). */
    public function updateTracking(Request $request, Order $order): OrderResource
    {
        $data = $request->validate([
            'tracking_company' => ['nullable', 'string', 'max:100'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'notify' => ['sometimes', 'boolean'],
        ]);
        if ($order->fulfillment_status !== 'fulfilled') {
            throw ValidationException::withMessages(['order' => __('订单还没有发货')]);
        }
        $numberChanged = ($data['tracking_number'] ?? null) !== $order->tracking_number;
        $order->update(['tracking_company' => $data['tracking_company'] ?? null, 'tracking_number' => $data['tracking_number'] ?? null]);
        if ($numberChanged) {
            app(\App\Shipping\PackageTracking::class)->reset($order);   // track the new parcel from scratch
        }
        $order->log('tracking', __('物流信息已更新：').(trim($order->tracking_company.' '.$order->tracking_number) ?: __('已清空')));
        if ($data['notify'] ?? false) {
            app(OrderNotifier::class)->send($order, 'shipping_confirmation');
        }

        return $this->detail($order);
    }

    /** "Refresh" on the order page: pull the latest tracking info now. */
    public function refreshTracking(Order $order, \App\Shipping\PackageTracking $tracking): OrderResource
    {
        if (! $tracking->enabled() || ! $order->tracking_number) {
            throw ValidationException::withMessages(['order' => __('没有可查询的物流单号')]);
        }
        if (! $order->tracking_registered_at) {
            $tracking->register($order);
        }
        $tracking->sync(collect([$order]));

        return $this->detail($order->fresh());
    }

    public function refund(Request $request, Order $order): OrderResource
    {
        $data = $request->validate([
            'items' => ['array', 'max:500'],
            'items.*.id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:0', 'max:100000'],
            'amount' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'shipping_amount' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'reason' => ['nullable', 'string', 'max:255'],
            'restock' => ['boolean'],
            'notify' => ['boolean'],
        ], ['amount.required' => __('请填写退款金额')]);

        app(OrderService::class)->refund($order, [
            ...$data,
            'amount' => Money::toMinor($data['amount']),
            'shipping_amount' => Money::toMinor($data['shipping_amount'] ?? 0),
        ]);

        return $this->detail($order);
    }

    public function cancel(Request $request, Order $order): OrderResource
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(array_keys(Order::CANCEL_REASONS))],
            'restock' => ['boolean'],
            'refund' => ['boolean'],
            'notify' => ['boolean'],
        ]);

        app(OrderService::class)->cancel($order, $data);

        return $this->detail($order);
    }

    public function comment(Request $request, Order $order): OrderResource
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:500']], ['message.required' => __('请输入备注内容')]);
        app(OrderService::class)->comment($order, trim($data['message']));

        return $this->detail($order);
    }

    /** Edit items before shipping (see App\Orders\OrderEditor). */
    public function edit(Request $request, Order $order, \App\Orders\OrderEditor $editor): JsonResponse
    {
        $data = $request->validate([
            'items' => ['array', 'max:200'],
            'items.*.id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:0', 'max:100000'],
            'add' => ['array', 'max:50'],
            'add.*.variant_id' => ['required', 'integer'],
            'add.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'notify' => ['boolean'],
        ]);
        $result = $editor->edit($order, $data['items'] ?? [], $data['add'] ?? [], (bool) ($data['notify'] ?? false));

        return response()->json(['data' => $this->detail($order)->resolve(), 'result' => $result]);
    }

    public function balanceReceived(Order $order, \App\Orders\OrderEditor $editor): OrderResource
    {
        $editor->balanceReceived($order);

        return $this->detail($order);
    }

    private function detail(Order $order): OrderResource
    {
        return new OrderResource($order->fresh()->load(self::DETAIL));
    }
}
