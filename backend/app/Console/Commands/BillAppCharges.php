<?php

namespace App\Console\Commands;

use App\Apps\AppBilling;
use Illuminate\Console\Command;

class BillAppCharges extends Command
{
    protected $signature = 'lumee:bill-app-charges';

    protected $description = 'Bill app subscriptions whose 30-day period has come round';

    public function handle(AppBilling $billing): int
    {
        $this->info('Billed '.$billing->billDue().' app charge(s).');

        return self::SUCCESS;
    }
}
