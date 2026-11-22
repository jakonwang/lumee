<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Tenancy\CurrentStore;
use App\Themes\ThemeSchedules;
use Illuminate\Console\Command;

class RunThemeSchedules extends Command
{
    protected $signature = 'lumee:theme-schedules {--prepare : Also make campaign versions for events entering their preparation window}';

    protected $description = 'Put scheduled campaign designs live, take finished ones down, and (with --prepare) prepare new ones from the shopping calendar';

    public function handle(ThemeSchedules $schedules, CurrentStore $current): int
    {
        $r = $schedules->runDue();
        $this->info("Started {$r['started']}, ended {$r['ended']} campaign design(s).");

        if ($this->option('prepare')) {
            $made = 0;
            Store::where('status', 'active')->get()->each(function (Store $store) use ($schedules, $current, &$made) {
                if (! ThemeSchedules::autoEnabled($store)) {
                    return;
                }
                $current->set($store);   // store-scoped lookups (products, markets…) for this store
                try {
                    $made += $schedules->prepare($store);
                } catch (\Throwable $e) {
                    report($e);
                } finally {
                    $current->set(null);
                }
            });
            $this->info("Prepared {$made} campaign design(s) for review.");
        }

        return self::SUCCESS;
    }
}
