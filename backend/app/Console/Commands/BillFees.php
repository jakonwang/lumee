<?php

namespace App\Console\Commands;

use App\Billing\BillingRunner;
use Illuminate\Console\Command;

class BillFees extends Command
{
    protected $signature = 'lumee:bill-fees';

    protected $description = 'Invoice each store\'s unbilled transaction fees (monthly)';

    public function handle(BillingRunner $runner): int
    {
        $s = $runner->billFees();
        $this->info("Invoiced {$s['invoiced']} stores, skipped {$s['skipped']}.");

        return self::SUCCESS;
    }
}
