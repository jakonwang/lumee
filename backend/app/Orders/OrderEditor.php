<?php

namespace App\Orders;

use App\Catalog\Preorders;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\Inventory;
use App\Services\OrderService;
use App\Services\TaxService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edit an order before it ships: change quantities, remove lines, add products. Stock moves with
 * the edit; tax is recalculated; the discount amount is kept (capped at the new subtotal).
 *
 * Money:
 *  - Unpaid (bank transfer / on account only): the total simply changes.
 *  - Paid: `total` stays what was collected. A cheaper order refunds the difference through the
 *    normal refund flow (the gateway refunds it); a dearer one records a balance due, which the
 *    merchant collects and then marks as received.
 */
class OrderEditor
{
    public const EDITABLE_UNPAID_GATEWAYS = ['manual', 'terms', 'test'];

    public function blocker(Order $order): ?string
    {
        return match (true) {
            $order->isCancelled() => __('订单已取消，不能编辑'),
            $order->fulfillment_status === 'fulfilled' => __('订单已发货，不能编辑'),
            in_array($order->financial_status, ['refunded', 'voided'], true) => __('订单已全额退款，不能编辑'),
            ! $order->isPaid() && ! in_array($order->payment_gateway, self::EDITABLE_UNPAID_GATEWAYS, true) => __('买家正在线上付款，请等付款完成后再编辑'),
            \App\Models\OrderDownload::where('order_id', $order->id)->exists() => __('数字商品已交付，不能编辑'),
            default => null,
        };
    }

    /**
     * @param  array<int, array{id:int, quantity:int}>  $changes  existing lines (0 removes the line)
     * @param  array<int, array{variant_id:int, quantity:int}>  $added
     * @return array{difference:int, refunded:int, balance_due:int}
     */
    public function edit(Order $order, array $changes, array $added, bool $notify = false): array
    {
        if ($why = $this->blocker($order)) {
            throw ValidationException::withMessages(['order' => $why]);
        }

        return DB::transaction(function () use ($order, $changes, $added, $notify) {
            /** @var Order $o */
            $o = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $o->load('items');
            $log = [];

            foreach ($changes as $c) {
                /** @var OrderItem|null $item */
                $item = $o->items->firstWhere('id', (int) $c['id']);
                if (! $item) {
                    throw ValidationException::withMessages(['items' => __('订单里没有这件商品')]);
                }
                $qty = (int) $c['quantity'];
                if ($qty === $item->quantity) {
                    continue;
                }
                if ($qty < (int) $item->refunded_quantity) {
                    throw ValidationException::withMessages(['items' => __('「:p0」已经退款 :p1 件，数量不能少于这个数', ['p0' => $item->title, 'p1' => $item->refunded_quantity])]);
                }
                $delta = $qty - $item->quantity;
                if ($delta > 0 && $item->variant_id) {
                    $this->assertStock($item->variant_id, $delta, $item->title);
                }
                if ($item->variant_id) {
                    Inventory::change($item->variant_id, -$delta, $delta > 0 ? 'order' : 'refund', $o->id, __('编辑订单'));
                }
                $log[] = $qty === 0 ? __('移除 :p0 ×:p1', ['p0' => $item->title, 'p1' => $item->quantity]) : __(':p0 数量 :p1 → :p2', ['p0' => $item->title, 'p1' => $item->quantity, 'p2' => $qty]);
                if ($qty === 0) {
                    $item->delete();
                } else {
                    $item->forceFill(['quantity' => $qty, 'total' => $item->price * $qty])->save();
                }
            }

            foreach ($added as $a) {
                $qty = (int) $a['quantity'];
                $v = ProductVariant::with('product')->find((int) $a['variant_id']);   // store-scoped
                if (! $v || $v->product?->status !== 'active' || $qty < 1) {
                    throw ValidationException::withMessages(['add' => __('商品不存在或已下架')]);
                }
                $this->assertStock($v->id, $qty, $v->product->title);
                $existing = $o->items()->where('variant_id', $v->id)->whereNull('plan')->first();
                $unit = (int) $v->price;
                if ($existing) {
                    $existing->forceFill(['quantity' => $existing->quantity + $qty, 'total' => $existing->price * ($existing->quantity + $qty)])->save();
                } else {
                    $o->items()->create([
                        'store_id' => $o->store_id, 'product_id' => $v->product_id, 'variant_id' => $v->id, 'title' => $v->product->title,
                        'variant_title' => $v->title === 'Default' ? null : $v->title, 'sku' => $v->sku,
                        'image' => $v->image?->url() ?? $v->product->featured_image, 'price' => $unit, 'quantity' => $qty, 'total' => $unit * $qty,
                        'preorder' => Preorders::isPreorder($v, $qty),
                    ]);
                }
                Inventory::change($v->id, -$qty, 'order', $o->id, __('编辑订单'));
                $log[] = __('添加 :p0 ×:p1', ['p0' => $v->product->title.($v->title !== 'Default' ? ' · '.$v->title : ''), 'p1' => $qty]);
            }

            $o->load('items');
            if ($o->items->isEmpty()) {
                throw ValidationException::withMessages(['items' => __('订单里至少要保留一件商品；不要了可以取消订单')]);
            }
            if (! $log) {
                throw ValidationException::withMessages(['items' => __('没有任何修改')]);
            }

            // New totals
            $store = Store::find($o->store_id);
            $subtotal = (int) $o->items->sum('total');
            $discount = min((int) $o->discount_total, $subtotal);
            $taxable = (int) $o->items->sum(fn ($i) => (\App\Models\Product::withoutGlobalScope('store')->find($i->product_id)?->taxable ?? true) ? $i->total : 0);
            $a = (array) $o->shipping_address;
            $tax = $o->company_id && \App\Models\Company::withoutGlobalScope('store')->find($o->company_id)?->tax_exempt
                ? ['amount' => 0, 'included' => (bool) $o->taxes_included, 'lines' => []]
                : app(TaxService::class)->calculate($store, TaxService::taxableAfterDiscount($subtotal, $taxable, $discount), (int) $o->shipping_total, $a['country'] ?? null, $a['province'] ?? null, $a);
            $newTotal = max(0, $subtotal - $discount + (int) $o->shipping_total + ($tax['included'] ? 0 : $tax['amount']) - (int) $o->gift_card_total);

            $o->forceFill([
                'subtotal' => $subtotal, 'discount_total' => $discount, 'tax_total' => $tax['amount'], 'taxes_included' => $tax['included'],
                'tax_lines' => $tax['lines'] ?: null, 'edits_count' => $o->edits_count + 1,
            ]);
            $result = ['difference' => 0, 'refunded' => 0, 'balance_due' => (int) $o->balance_due];

            if (! $o->isPaid()) {
                $result['difference'] = $newTotal - (int) $o->total;
                $o->total = $newTotal;
                $o->save();
            } else {
                $covered = (int) $o->total - (int) $o->refunded_total + (int) $o->balance_due;
                $diff = $newTotal - $covered;
                $result['difference'] = $diff;
                if ($diff < 0) {
                    // First reduce any balance still owed, then refund the rest
                    $fromDue = min((int) $o->balance_due, -$diff);
                    $o->balance_due = (int) $o->balance_due - $fromDue;
                    $o->save();
                    $refund = -$diff - $fromDue;
                    if ($refund > 0) {
                        app(OrderService::class)->refund($o, ['amount' => $refund, 'items' => [], 'reason' => __('编辑订单后退还差价'), 'restock' => false, 'notify' => $notify]);
                        $result['refunded'] = $refund;
                    }
                } else {
                    $o->balance_due = (int) $o->balance_due + $diff;
                    $o->save();
                }
                $result['balance_due'] = (int) $o->fresh()->balance_due;
            }

            $summary = implode('；', $log);
            $money = fn ($v) => Money::display(abs($v), $o->currency);
            $o->log('edited', __('编辑了订单：:p0', ['p0' => $summary]).match (true) {
                $result['refunded'] > 0 => __('。已退还差价 :p0', ['p0' => $money($result['refunded'])]),
                $o->isPaid() && $result['difference'] > 0 => __('。买家需要补付 :p0', ['p0' => $money($result['difference'])]),
                default => '',
            });
            app(\App\Webhooks\Webhooks::class)->orderEvent($o->fresh(), 'orders/updated');

            return $result;
        });
    }

    /** The merchant collected the balance (e.g. by bank transfer or a payment link). */
    public function balanceReceived(Order $order): void
    {
        if ((int) $order->balance_due <= 0) {
            throw ValidationException::withMessages(['order' => __('这个订单没有待补付的金额')]);
        }
        $due = (int) $order->balance_due;
        $order->forceFill(['total' => (int) $order->total + $due, 'balance_due' => 0])->save();
        $order->log('paid', __('已收到补付的 :p0', ['p0' => Money::display($due, $order->currency)]));
    }

    private function assertStock(int $variantId, int $qty, string $title): void
    {
        $v = ProductVariant::withoutGlobalScope('store')->with('product')->find($variantId);
        if ($v && ! Preorders::allows($v, $qty)) {
            throw ValidationException::withMessages(['items' => __('「:p0」库存只剩 :p1 件', ['p0' => $title, 'p1' => max(0, (int) $v->inventory_quantity)])]);
        }
    }
}
