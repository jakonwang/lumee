<?php

namespace App\Console\Commands;

use App\Billing\BillingRunner;
use Illuminate\Console\Command;

class BillingDaily extends Command
{
    protected $signature = 'lumee:billing-daily';

    protected $description = 'Renew/expire non-Stripe subscriptions and send trial-ending reminders';

    public function handle(BillingRunner $runner): int
    {
        $s = $runner->daily();
        $this->info("Renewed {$s['renewed']}, ended {$s['ended']}, trial reminders {$s['reminded']}.");

        return self::SUCCESS;
    }
}
