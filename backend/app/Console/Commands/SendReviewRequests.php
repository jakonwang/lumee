<?php

namespace App\Console\Commands;

use App\Reviews\ReviewRequests;
use Illuminate\Console\Command;

class SendReviewRequests extends Command
{
    protected $signature = 'lumee:review-requests';

    protected $description = 'Ask buyers to review their order a few days after it shipped';

    public function handle(ReviewRequests $requests): int
    {
        $this->info('Sent '.$requests->run().' review request(s).');

        return self::SUCCESS;
    }
}
