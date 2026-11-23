<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Store;
use App\Notifications\OrderNotifier;
use App\Services\OrderService;
use App\Tenancy\CurrentStore;
use Illuminate\Console\Command;

/**
 * Hourly: an hour in, remind the buyer once (email with a "Complete payment" link); later, cancel orders whose online payment was never completed, so they stop holding stock, discount-code uses and
 * gift card balances. Stock is taken when the order is placed (before the buyer reaches Stripe / PayPal…), so a buyer
 * who closes the payment page would otherwise keep it forever.
 *  - Card / wallet payments: after 24 hours (Stripe checkout links stop working then too).
 *  - Vouchers and bank slips the buyer pays later (OXXO, boleto…): after 7 days, if Stripe didn't report the expiry first.
 * Offline methods (bank transfer, cash on delivery, payment terms) are left to the merchant.
 */
class ExpireUnpaidOrders extends Command
{
    protected $signature = 'lumee:expire-unpaid-orders';

    protected $description = 'Cancel online-payment orders that were never paid, releasing their stock';

    public const ONLINE = ['stripe', 'paypal', 'airwallex', 'razorpay'];

    public const HOURS = 24;

    public const VOUCHER_DAYS = 7;

    public function handle(OrderService $orders, CurrentStore $current, OrderNotifier $notifier): int
    {
        // An hour in: remind the buyer once, with a link back to the payment page
        $reminded = 0;
        Order::withoutGlobalScope('store')->whereIn('payment_gateway', self::ONLINE)->where('financial_status', 'pending')
            ->whereNull('cancelled_at')->where('total', '>', 0)
            ->whereBetween('created_at', [now()->subHours(self::HOURS - 1), now()->subHour()])
            ->whereDoesntHave('events', fn ($q) => $q->where('type', 'payment_pending'))
            ->whereNotExists(fn ($q) => $q->from('order_notifications')->whereColumn('order_notifications.order_id', 'orders.id')->where('type', 'payment_reminder'))
            ->orderBy('id')->chunkById(200, function ($batch) use ($notifier, &$reminded) {
                foreach ($batch as $order) {
                    if ($notifier->send($order, 'payment_reminder')) {
                        $reminded++;
                    }
                }
            });

        $count = 0;
        Order::withoutGlobalScope('store')->whereIn('payment_gateway', self::ONLINE)->where('financial_status', 'pending')
            ->whereNull('cancelled_at')->where('created_at', '<', now()->subHours(self::HOURS))
            ->orderBy('id')->chunkById(200, function ($batch) use ($orders, $current, &$count) {
                foreach ($batch as $order) {
                    $voucher = $order->events()->where('type', 'payment_pending')->exists();
                    if ($voucher && $order->created_at->gt(now()->subDays(self::VOUCHER_DAYS))) {
                        continue;   // still within the time the buyer has to pay at the shop / bank
                    }
                    $current->set(Store::findOrFail($order->store_id));
                    if (self::cancel($orders, $order)) {
                        $count++;
                    }
                }
            });
        $this->info("Reminded {$reminded} and cancelled {$count} unpaid order(s).");

        return self::SUCCESS;
    }

    /** Cancel an unpaid order, putting its stock back (no email: the buyer chose not to pay). */
    public static function cancel(OrderService $orders, Order $order): bool
    {
        return (bool) rescue(fn () => $orders->cancel($order, ['reason' => 'unpaid', 'refund' => false, 'restock' => true, 'notify' => false]), false);
    }
}
