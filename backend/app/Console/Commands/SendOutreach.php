<?php

namespace App\Console\Commands;

use App\Models\OutreachEnrollment;
use App\Models\Store;
use App\Outreach\ReplyChecker;
use App\Outreach\Sequencer;
use App\Tenancy\CurrentStore;
use Illuminate\Console\Command;
use Throwable;

/**
 * Outreach sequences: send the next due email per store (pace and limits in App\Outreach\Sequencer),
 * or with --replies read each store's inbox for replies and bounces.
 */
class SendOutreach extends Command
{
    protected $signature = 'lumee:outreach {--replies : check inboxes for replies and bounces instead of sending}';

    protected $description = 'Send due outreach emails from each store\'s mailbox, or check for replies';

    public function handle(CurrentStore $current, Sequencer $sequencer, ReplyChecker $replies): int
    {
        // Only stores that have something going on
        $storeIds = OutreachEnrollment::withoutGlobalScope('store')
            ->whereIn('status', $this->option('replies') ? ['active', 'completed'] : ['active'])
            ->when(! $this->option('replies'), fn ($q) => $q->where('next_at', '<=', now()))
            ->distinct()->pluck('store_id');
        $n = 0;
        foreach (Store::whereIn('id', $storeIds)->where('status', 'active')->get() as $store) {
            try {
                $current->set($store);
                if ($this->option('replies')) {
                    $r = $replies->check($store);
                    $n += $r['replied'] + $r['bounced'];
                } else {
                    $n += $sequencer->runStore($store)?->status === 'sent' ? 1 : 0;
                }
            } catch (Throwable $e) {
                report($e);
            }
        }
        $current->set(null);
        $this->info($this->option('replies') ? "{$n} reply/bounce(s)." : "{$n} email(s) sent.");

        return self::SUCCESS;
    }
}
