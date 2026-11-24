<?php

namespace App\LeadFinder;

use App\Models\Lead;
use App\Models\LeadSearch;
use App\Tenancy\CurrentStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Throwable;

/**
 * Runs a lead search in the background: find → save → read each website → score (AI when enabled).
 * Progress is written to the search row so the admin can show it live. Each lead is independent:
 * an unreachable site or a failed AI call doesn't stop the rest.
 */
class RunLeadSearch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 3000;

    /** @param int[]|null $leadIds only (re)process these leads — used for "re-check selected" */
    public function __construct(public int $searchId, public int $limit = 60, public ?array $leadIds = null, public bool $analyze = false) {}

    public function handle(CurrentStore $current, LeadPipeline $pipeline): void
    {
        // Claim it atomically: if the queue hands the same job out again (long runs pass retry_after), the copy stops here
        $claimed = LeadSearch::withoutGlobalScope('store')->whereKey($this->searchId)->where('status', 'queued')
            ->update(['status' => 'running', 'started_at' => now(), 'error' => null]);
        $search = $claimed ? LeadSearch::withoutGlobalScope('store')->with('store')->find($this->searchId) : null;
        if (! $search) {
            return;
        }
        $current->set($search->store);
        $store = $search->store;
        $terms = $search->industry === 'custom' ? $search->keywords : [];

        try {
            // 1. Find
            if ($this->leadIds === null && $search->source !== 'manual') {
                $search->update(['stage' => 'searching']);
                $places = array_values(array_filter($pipeline->find($store, $search, $this->limit, fn (int $n) => $search->update(['found' => $n])),
                    fn ($p) => ! LeadProfiles::isChain($p['name'])));
                $new = 0;
                foreach ($places as $place) {
                    [, $isNew] = $pipeline->upsert($place, $search);
                    $new += $isNew ? 1 : 0;
                }
                $search->update(['found' => count($places), 'new_leads' => $new]);
            }

            // 2. Read websites + score
            $leads = Lead::query()
                ->when($this->leadIds !== null, fn ($q) => $q->whereIn('id', $this->leadIds), fn ($q) => $q->where('lead_search_id', $search->id))
                ->orderBy('id')->get();
            $search->update(['stage' => 'enriching', 'to_enrich' => $leads->count(), 'enriched' => 0]);
            $ai = ($this->analyze || LeadSettings::aiAuto($store)) && LeadAi::ready($store);
            foreach ($leads as $i => $lead) {
                if ($this->leadIds !== null || $lead->enrich_status === 'pending') {
                    $pipeline->enrich($lead);
                }
                $pipeline->score($lead, $terms);
                if ($ai) {
                    try {
                        $pipeline->analyze($store, $lead, $terms);
                    } catch (Throwable $e) {
                        report($e);
                        $ai = ! str_contains($e->getMessage(), 'Key');   // a bad key fails every call: stop trying
                    }
                }
                $search->update(['enriched' => $i + 1]);
                if ($search->fresh()?->status !== 'running') {
                    return;   // cancelled from the admin
                }
            }
            $search->update(['status' => 'done', 'stage' => null, 'finished_at' => now()]);
            // Sequences set to take new leads automatically (score, type, country rules)
            if ($this->leadIds === null) {
                rescue(fn () => app(\App\Outreach\Sequencer::class)->autoEnroll($store, $leads->each->refresh(), 'leads'));
            }
        } catch (Throwable $e) {
            report($e);
            $search->update(['status' => 'failed', 'stage' => null, 'error' => mb_substr($e->getMessage(), 0, 500), 'finished_at' => now()]);
        }
    }

    public function failed(Throwable $e): void
    {
        if ($e instanceof MaxAttemptsExceededException) {
            return;   // a re-delivered copy of a job that is still running — the original finishes it
        }
        LeadSearch::withoutGlobalScope('store')->whereKey($this->searchId)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'failed', 'stage' => null, 'error' => '任务中断：'.mb_substr($e->getMessage(), 0, 400), 'finished_at' => now()]);
    }
}
