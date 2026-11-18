<?php

namespace App\Console\Commands;

use App\Subscriptions\Subscriptions;
use Illuminate\Console\Command;

class RenewSubscriptions extends Command
{
    protected $signature = 'lumee:renew-subscriptions';

    protected $description = 'Create and charge renewal orders for subscriptions that are due';

    public function handle(Subscriptions $subscriptions): int
    {
        $this->info($subscriptions->renewDue().' subscriptions renewed');

        return self::SUCCESS;
    }
}
