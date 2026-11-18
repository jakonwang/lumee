<?php

namespace App\Console\Commands;

use App\Billing\PartnerPayouts;
use Illuminate\Console\Command;

class CreatePartnerPayouts extends Command
{
    protected $signature = 'lumee:partner-payouts {--period= : YYYY-MM (defaults to last month)}';

    protected $description = 'Gather developers’ paid marketplace earnings into monthly payouts';

    public function handle(PartnerPayouts $payouts): int
    {
        $period = $this->option('period') ?: now()->subMonthNoOverflow()->format('Y-m');
        if (! preg_match('/^\d{4}-\d{2}$/', $period)) {
            $this->error('Period must be YYYY-MM.');

            return self::FAILURE;
        }
        $this->info("Created {$payouts->run($period)} payout(s) for {$period}.");

        return self::SUCCESS;
    }
}
