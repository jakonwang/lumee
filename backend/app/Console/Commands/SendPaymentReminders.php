<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Store;
use App\Notifications\B2BMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/** Pay-on-account orders past their due date: one reminder email to the buyer, noted on the order. */
class SendPaymentReminders extends Command
{
    protected $signature = 'lumee:payment-reminders';

    protected $description = 'Email buyers whose on-account (Net terms) orders are overdue';

    public function handle(): int
    {
        $n = 0;
        $orders = Order::withoutGlobalScope('store')->where('payment_gateway', 'terms')->where('financial_status', 'pending')->whereNull('cancelled_at')
            ->whereNotNull('payment_due_at')->where('payment_due_at', '<', now())->whereNull('payment_reminded_at')
            ->orderBy('id')->limit(500)->get();
        foreach ($orders as $order) {
            $store = Store::find($order->store_id);
            try {
                Mail::to($order->email)->locale($order->locale ?: \App\I18n\Localization::storeDefault($store))->send(new B2BMail($store, 'payment_overdue', ['order' => $order]));
                $order->forceFill(['payment_reminded_at' => now()])->save();
                $order->log('terms', __('账期已到期，已发送付款提醒'));
                $n++;
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $this->info("$n reminders sent");

        return self::SUCCESS;
    }
}
