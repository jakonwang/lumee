<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Tenancy\CurrentStore;
use App\Testing\TestAndLearn;
use Illuminate\Console\Command;
use Throwable;

class EvaluateProductTests extends Command
{
    protected $signature = 'lumee:test-and-learn';

    protected $description = 'Re-evaluate every active product (hot / testing / cold / no traffic) for test-and-learn';

    public function handle(TestAndLearn $tests, CurrentStore $current): int
    {
        $n = 0;
        foreach (Store::where('status', 'active')->cursor() as $store) {
            try {
                $current->set($store);   // tenant for store-scoped models
                $n += $tests->evaluate($store);
            } catch (Throwable $e) {
                report($e);
            }
        }
        $current->set(null);
        $this->info("Evaluated {$n} product(s).");

        return self::SUCCESS;
    }
}
