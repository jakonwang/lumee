<?php

namespace App\Jobs;

use App\Marketing\ServerEvents;
use App\Models\Order;
use App\Tenancy\CurrentStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Sends one paid order to Meta / TikTok in the background (never blocks a payment webhook). */
class SendServerEvents implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public int $orderId) {}

    public function handle(ServerEvents $events, CurrentStore $current): void
    {
        $order = Order::withoutGlobalScope('store')->with('items', 'store')->find($this->orderId);
        if ($order) {
            $current->set($order->store);
            $events->purchase($order);
        }
    }
}
