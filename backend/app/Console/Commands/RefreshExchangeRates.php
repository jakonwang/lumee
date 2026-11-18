<?php

namespace App\Console\Commands;

use App\Currency\ExchangeRates;
use Illuminate\Console\Command;
use Throwable;

class RefreshExchangeRates extends Command
{
    protected $signature = 'lumee:exchange-rates';

    protected $description = 'Fetch today\'s exchange rates for storefront currency conversion';

    public function handle(ExchangeRates $rates): int
    {
        try {
            $n = $rates->refresh();
        } catch (Throwable $e) {
            report($e);
            $this->error('Could not refresh exchange rates (previous rates stay in use): '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info("Updated {$n} exchange rates.");

        return self::SUCCESS;
    }
}
