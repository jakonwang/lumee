<?php

namespace App\Jobs;

use App\Models\SmsMessage;
use App\Notifications\Sms;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class SendSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public int $messageId) {}

    public function handle(Sms $sms): void
    {
        $msg = SmsMessage::withoutGlobalScope('store')->find($this->messageId);
        if ($msg && $msg->status !== 'sent') {
            $sms->deliver($msg);
        }
    }

    public function failed(Throwable $e): void
    {
        SmsMessage::withoutGlobalScope('store')->whereKey($this->messageId)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
    }
}
