<?php

namespace App\Console\Commands;

use App\Marketing\CampaignSender;
use Illuminate\Console\Command;

class SendScheduledCampaigns extends Command
{
    protected $signature = 'lumee:send-campaigns';

    protected $description = 'Start email campaigns whose scheduled time has come';

    public function handle(CampaignSender $sender): int
    {
        $this->info('Started '.$sender->startDue().' scheduled campaign(s).');

        return self::SUCCESS;
    }
}
