<?php

namespace App\Jobs;

use App\Marketing\CampaignSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Sends up to CampaignSender::BATCH emails of a campaign. Safe to retry: only "queued" recipients are sent. */
class SendCampaignBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public int $campaignId, public array $recipientIds) {}

    public function handle(CampaignSender $sender): void
    {
        $sender->sendBatch($this->campaignId, $this->recipientIds);
    }
}
