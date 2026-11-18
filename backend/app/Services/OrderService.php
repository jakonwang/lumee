<?php

namespace App\Services;

use App\Models\Discount;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Notifications\OrderNotifier;
use App\Payments\GatewayRegistry;
use App\Payments\ManualGateway;
use App\Payments\PaymentGateway;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Merchant-side order lifecycle: refunds (full / partial, with restock) and cancellation.
 * Every change locks the order row, so two staff clicking "refund" at once can't refund twice.
 */
class OrderService
{
    /**
     * @param  array{items?: array<int, array{id:int, quantity:int}>, amount: int, shipping_amount?: int,
     *               reason?: ?string, restock?: bool, notify?: bool}  $data  amounts in minor units
     */
    public function refund(Order $order, array $data): Refund
    {
        $refund = DB::transaction(function () use ($order, $data) {
            /** @var Order $locked */
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $locked->load('items');

            if (! $locked->isPaid()) {
                throw ValidationException::withMessages(['amount' => __('订单还没有付款，不能退款')]);
            }

            $amount = (int) $data['amount'];
            $shipping = (int) ($data['shipping_amount'] ?? 0);
            $lines = $this->refundLines($locked, $data['items'] ?? []);

            if ($amount < 0 || $amount > $locked->refundable()) {
                throw ValidationException::withMessages(['amount' => __('退款金额不能超过可退金额 ').Money::display($locked->refundable(), $locked->currency)]);
            }
            $shippingLeft = $locked->shipping_total - (int) $locked->refunds()->sum('shipping_amount');
            if ($shipping < 0 || $shipping > $shippingLeft) {
                throw ValidationException::withMessages(['shipping_amount' => __('运费退款不能超过 ').Money::display($shippingLeft, $locked->currency)]);
            }
            if ($shipping > $amount) {
                throw ValidationException::withMessages(['amount' => __('退款金额不能小于运费退款')]);
            }
            if ($amount === 0 && ! $lines) {
                throw ValidationException::withMessages(['amount' => __('请选择要退的商品或填写退款金额')]);
            }

            $restock = (bool) ($data['restock'] ?? false) && ! $this->alreadyRestockedOnCancel($locked);
            $gateway = $this->gateway($locked);

            // Money moves last, right before we record it: a gateway failure leaves nothing half-done
            $reference = null;
            if ($amount > 0) {
                try {
                    $reference = $gateway->refund($locked, $amount, "refund-{$locked->id}-{$locked->refunded_total}-{$amount}");
                } catch (RuntimeException $e) {
                    throw ValidationException::withMessages(['amount' => $e->getMessage()]);
                }
            }

            foreach ($lines as [$item, $qty]) {
                $item->increment('refunded_quantity', $qty);
                if ($restock && $item->variant_id) {
                    \App\Services\Inventory::change($item->variant_id, $qty, 'refund', $locked->id);
                }
            }

            $refund = Refund::create([
                'store_id' => $locked->store_id,
                'order_id' => $locked->id,
                'user_id' => auth()->id(),
                'amount' => $amount,
                'shipping_amount' => $shipping,
                'reason' => isset($data['reason']) ? mb_substr((string) $data['reason'], 0, 255) : null,
                'restock' => $restock && $lines,
                'gateway' => $locked->payment_gateway,
                'reference' => $reference,
                'items' => array_map(fn ($l) => ['order_item_id' => $l[0]->id, 'quantity' => $l[1]], $lines),
            ]);

            $refunded = $locked->refunded_total + $amount;
            $locked->forceFill([
                'refunded_total' => $refunded,
                'financial_status' => $refunded >= $locked->total ? 'refunded' : ($refunded > 0 ? 'partially_refunded' : $locked->financial_status),
            ])->save();
            if ($amount > 0) {
                app(\App\Billing\FeeLedger::class)->recordRefund($locked, $amount);   // no platform fee on refunded money
                app(\App\Marketing\Affiliates::class)->orderChanged($locked);         // smaller commission
            }
            if ($amount > 0 && $locked->customer) {
                $locked->customer->forceFill(['total_spent' => max(0, $locked->customer->total_spent - $amount)])->save();
            }

            $qty = array_sum(array_map(fn ($l) => $l[1], $lines));
            $msg = $amount > 0 ? __('退款 ').Money::display($amount, $locked->currency) : __('退回商品（未退款）');
            if ($qty) {
                $msg .= __(' · :p0 件商品', ['p0' => $qty]).($refund->restock ? __('，已回补库存') : '');
            }
            if ($amount > 0 && ! $gateway->refundsOnline()) {
                $msg .= __(' · 需线下退款给买家');
            }
            $locked->log('refunded', $msg, array_filter(['reason' => $refund->reason, 'reference' => $reference]));

            return $refund;
        });

        if (($data['notify'] ?? false) && $refund->amount > 0) {
            app(OrderNotifier::class)->send($order->fresh(), 'refund_notification');
        }
        app(\App\Webhooks\Webhooks::class)->orderEvent($order, 'orders/refunded');

        return $refund;
    }

    /**
     * @param  array{reason: string, restock?: bool, refund?: bool, notify?: bool}  $data
     */
    public function cancel(Order $order, array $data): Order
    {
        $restock = (bool) ($data['restock'] ?? true);

        // 1) Paid orders: refund everything that's left first (its own transaction + gateway call)
        $fresh = $order->fresh('items');
        if ($fresh->isCancelled()) {
            throw ValidationException::withMessages(['order' => __('订单已经取消')]);
        }
        if ($fresh->fulfillment_status !== 'unfulfilled') {
            throw ValidationException::withMessages(['order' => __('已发货的订单不能取消，请使用退款')]);
        }
        if (($data['refund'] ?? true) && $fresh->refundable() > 0) {
            $this->refund($fresh, [
                'items' => $fresh->items->filter(fn ($i) => $i->refundableQuantity() > 0)
                    ->map(fn ($i) => ['id' => $i->id, 'quantity' => $i->refundableQuantity()])->values()->all(),
                'amount' => $fresh->refundable(),
                'shipping_amount' => $fresh->shipping_total - (int) $fresh->refunds()->sum('shipping_amount'),
                'reason' => isset(Order::CANCEL_REASONS[$data['reason']]) ? __(Order::CANCEL_REASONS[$data['reason']]) : null,
                'restock' => $restock,
                'notify' => false,   // the cancellation email already mentions the refund
            ]);
        }

        // 2) Cancel
        $cancelled = DB::transaction(function () use ($order, $data, $restock) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $locked->load('items');
            if ($locked->isCancelled()) {
                throw ValidationException::withMessages(['order' => __('订单已经取消')]);
            }

            // Restock what the refund above didn't already put back
            $restocked = 0;
            if ($restock) {
                foreach ($locked->items as $item) {
                    $qty = $item->refundableQuantity();
                    if ($qty > 0 && $item->variant_id) {
                        \App\Services\Inventory::change($item->variant_id, $qty, 'cancel', $locked->id);
                        $restocked += $qty;
                    }
                }
            }

            $locked->forceFill([
                'cancelled_at' => now(),
                'cancel_reason' => $data['reason'],
                'financial_status' => $locked->financial_status === 'pending' ? 'voided' : $locked->financial_status,
            ])->save();

            if ($locked->discount_code) {
                Discount::where('code', $locked->discount_code)->where('usage_count', '>', 0)->decrement('usage_count');
            }
            app(\App\Marketing\Affiliates::class)->orderChanged($locked);   // void the commission
            if ($taken = app(\App\Loyalty\Points::class)->reverse($locked)) {
                $locked->log('points', __('已扣回本单获得的 :p0 积分', ['p0' => $taken]));
            }
            if ($restored = app(\App\GiftCards\GiftCards::class)->restore($locked)) {
                $locked->log('gift_card', __('已退回礼品卡余额 :p0', ['p0' => \App\Support\Money::format($restored).' '.$locked->currency]));
            }

            $locked->log('cancelled', __('订单已取消：').__(Order::CANCEL_REASONS[$data['reason']] ?? $data['reason'])
                .($restocked ? __('，已回补 :p0 件库存', ['p0' => $restocked]) : ''), ['reason' => $data['reason'], 'restocked' => $restock]);

            return $locked;
        });

        if ($data['notify'] ?? false) {
            app(OrderNotifier::class)->send($cancelled, 'order_cancelled');
        }
        app(\App\Webhooks\Webhooks::class)->orderEvent($cancelled, 'orders/cancelled');

        return $cancelled;
    }

    public function comment(Order $order, string $message): OrderEvent
    {
        return $order->log('comment', $message);
    }

    /** @return array<int, array{0: \App\Models\OrderItem, 1: int}> */
    private function refundLines(Order $order, array $items): array
    {
        $lines = [];
        foreach ($items as $row) {
            $qty = (int) ($row['quantity'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $item = $order->items->firstWhere('id', (int) ($row['id'] ?? 0));
            if (! $item) {
                throw ValidationException::withMessages(['items' => __('商品不属于这个订单')]);
            }
            if ($qty > $item->refundableQuantity()) {
                throw ValidationException::withMessages(['items' => __('「:p0」最多还能退 :p1 件', ['p0' => $item->title, 'p1' => $item->refundableQuantity()])]);
            }
            $lines[] = [$item, $qty];
        }

        return $lines;
    }

    /** A cancel that kept the money (refund later) may already have put the stock back. */
    private function alreadyRestockedOnCancel(Order $order): bool
    {
        return $order->isCancelled() && OrderEvent::where('order_id', $order->id)->where('type', 'cancelled')
            ->get()->contains(fn ($e) => (bool) ($e->data['restocked'] ?? false));
    }

    /** The order's gateway even if it has since been switched off for new checkouts. */
    private function gateway(Order $order): PaymentGateway
    {
        return app(GatewayRegistry::class)->all()->get($order->payment_gateway) ?? app(ManualGateway::class);
    }
}
