<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Tenancy\CurrentStore;
use App\Themes\DesignInsights;
use Illuminate\Console\Command;

class WeeklyDesignInsights extends Command
{
    protected $signature = 'lumee:design-insights';

    protected $description = "Look at last week's numbers for every store and market: findings and AI design suggestions";

    public function handle(DesignInsights $insights, CurrentStore $current): int
    {
        $sites = 0;
        Store::where('status', 'active')->get()->each(function (Store $store) use ($insights, $current, &$sites) {
            $current->set($store);
            try {
                $sites += $insights->weekly($store);
            } catch (\Throwable $e) {
                report($e);
            } finally {
                $current->set(null);
            }
        });
        $this->info("Looked at {$sites} site(s).");

        return self::SUCCESS;
    }
}
