<?php

namespace App\Console\Commands;

use App\Shipping\PackageTracking;
use Illuminate\Console\Command;

class SyncTracking extends Command
{
    protected $signature = 'lumee:tracking-sync';

    protected $description = 'Refresh package tracking for shipped orders that are not delivered yet';

    public function handle(PackageTracking $tracking): int
    {
        if (! $tracking->enabled()) {
            $this->info('Package tracking is off (SEVENTEEN_TRACK_KEY is not set).');

            return self::SUCCESS;
        }
        $this->info('Updated '.$tracking->sync($tracking->due()).' order(s).');

        return self::SUCCESS;
    }
}
