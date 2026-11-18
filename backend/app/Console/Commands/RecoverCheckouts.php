<?php

namespace App\Console\Commands;

use App\Marketing\CheckoutRecovery;
use Illuminate\Console\Command;

class RecoverCheckouts extends Command
{
    protected $signature = 'lumee:recover-checkouts';

    protected $description = 'Email shoppers who started a checkout but did not finish it';

    public function handle(CheckoutRecovery $recovery): int
    {
        $this->info('Sent '.$recovery->run().' abandoned checkout email(s).');

        return self::SUCCESS;
    }
}
