<?php

namespace App\Console\Commands;

use App\Markets\MarketFit;
use App\Models\Store;
use App\Tenancy\CurrentStore;
use Illuminate\Console\Command;
use Throwable;

/** Daily: re-score which markets each product suits (sales, local trends and seasons change). No AI calls. */
class ScoreMarketFit extends Command
{
    protected $signature = 'lumee:market-fit {--store= : only this store id}';

    protected $description = 'Re-score how well every product suits each market (storefront ranking, publish where it fits)';

    public function handle(CurrentStore $current): int
    {
        $n = 0;
        $stores = Store::where('status', 'active')->when($this->option('store'), fn ($q, $id) => $q->whereKey((int) $id));
        foreach ($stores->cursor() as $store) {
            try {
                $current->set($store);   // tenant for store-scoped models
                $n += app(MarketFit::class)->scoreAll($store);
            } catch (Throwable $e) {
                report($e);
            }
        }
        $current->set(null);
        $this->info("Scored {$n} product(s).");

        return self::SUCCESS;
    }
}
