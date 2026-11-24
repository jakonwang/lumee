<?php

namespace App\Console\Commands;

use App\LeadFinder\RunLeadSearch;
use App\Models\LeadSearch;
use Illuminate\Console\Command;

/** Lead searches marked "every day" run again (same country, cities, keywords, channels): only new companies become new leads. */
class RepeatLeadSearches extends Command
{
    protected $signature = 'lumee:repeat-lead-searches';

    protected $description = 'Run daily-repeating lead searches again';

    public function handle(): int
    {
        $n = 0;
        $due = LeadSearch::withoutGlobalScope('store')->where('repeat_daily', true)->where('source', '!=', 'manual')
            ->where(fn ($q) => $q->whereNull('repeated_at')->orWhere('repeated_at', '<', now()->subHours(20)))->get();
        foreach ($due as $s) {
            if (LeadSearch::withoutGlobalScope('store')->where('store_id', $s->store_id)->whereIn('status', ['queued', 'running'])->count() >= 3) {
                continue;   // the store is busy: tomorrow
            }
            $copy = $s->replicate(['status', 'stage', 'found', 'new_leads', 'enriched', 'to_enrich', 'error', 'started_at', 'finished_at', 'repeat_daily', 'repeated_at']);
            $copy->forceFill(['store_id' => $s->store_id, 'name' => $s->name.' · '.now()->format('m-d'), 'status' => 'queued', 'repeat_daily' => false])->save();
            $s->forceFill(['repeated_at' => now()])->save();
            RunLeadSearch::dispatch($copy->id, 60);
            $n++;
        }
        $this->info("Started {$n} search(es).");

        return self::SUCCESS;
    }
}
