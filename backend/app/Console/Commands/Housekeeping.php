<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Daily: drop data that's no longer needed so tables don't grow forever.
 *  - AI shopping assistant questions after 180 days (merchants see the latest 50 anyway).
 *  - Variant price history older than 400 days — keeping, per variant and site, the last price from before
 *    that point, which the EU "lowest price of the 30 days before a discount" rule may still need.
 */
class Housekeeping extends Command
{
    protected $signature = 'lumee:housekeeping';

    protected $description = 'Prune old assistant questions and price history';

    public const QUESTION_DAYS = 180;

    public const PRICE_DAYS = 400;

    public function handle(): int
    {
        $questions = DB::table('assistant_questions')->where('created_at', '<', now()->subDays(self::QUESTION_DAYS))->delete();

        $cutoff = now()->subDays(self::PRICE_DAYS);
        $prices = 0;
        // Not chunk(): a pruned group drops out of the HAVING result, so paging by offset would skip groups.
        // Each pass takes the first 500 groups that still need pruning, until none are left.
        do {
            $groups = DB::table('variant_price_history')->where('created_at', '<', $cutoff)
                ->groupBy('product_variant_id', 'market_id')->havingRaw('COUNT(*) > 1')
                ->selectRaw('product_variant_id, market_id, MAX(id) as keep_id')
                ->orderBy('product_variant_id')->limit(500)->get();
            foreach ($groups as $g) {
                $prices += DB::table('variant_price_history')->where('product_variant_id', $g->product_variant_id)
                    ->when($g->market_id === null, fn ($q) => $q->whereNull('market_id'), fn ($q) => $q->where('market_id', $g->market_id))
                    ->where('created_at', '<', $cutoff)->where('id', '<', $g->keep_id)->delete();
            }
        } while ($groups->count() === 500);

        $this->info("Removed {$questions} assistant questions and {$prices} old price records.");

        return self::SUCCESS;
    }
}
