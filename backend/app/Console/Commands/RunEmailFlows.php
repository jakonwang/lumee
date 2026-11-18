<?php

namespace App\Console\Commands;

use App\Marketing\Flows;
use Illuminate\Console\Command;

class RunEmailFlows extends Command
{
    protected $signature = 'lumee:run-flows';

    protected $description = 'Send due automated emails (welcome, post-purchase, win-back)';

    public function handle(Flows $flows): int
    {
        $this->info('Sent '.$flows->run().' automated email(s).');

        return self::SUCCESS;
    }
}
