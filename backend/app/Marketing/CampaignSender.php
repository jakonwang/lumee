<?php

namespace App\Marketing;

use App\Jobs\SendCampaignBatch;
use App\Models\Customer;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\Store;
use App\Notifications\CampaignMail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends email campaigns: picks the audience (subscribed customers only), creates one recipient
 * row per address, then sends in queued batches. Each recipient has a token for the open pixel,
 * tracked links and one-click unsubscribe.
 */
class CampaignSender
{
    public const BATCH = 100;

    /** Subscribed customers for an audience (store-scoped by the caller's store). */
    public function audience(Store $store, string $audience): Builder
    {
        return Customer::withoutGlobalScope('store')->where('store_id', $store->id)
            ->where('accepts_marketing', true)->whereNotNull('email')
            ->when($audience === 'customers', fn ($q) => $q->where('orders_count', '>', 0))
            ->when($audience === 'prospects', fn ($q) => $q->where(fn ($w) => $w->whereNull('orders_count')->orWhere('orders_count', 0)));
    }

    /** Start sending now: snapshot the audience into recipient rows and queue the batches. */
    public function start(EmailCampaign $campaign): void
    {
        $claimed = EmailCampaign::withoutGlobalScope('store')->whereKey($campaign->id)
            ->whereIn('status', ['draft', 'scheduled'])->update(['status' => 'sending']);
        if (! $claimed) {
            return;   // already started by another run
        }
        $campaign->refresh();
        $store = Store::findOrFail($campaign->store_id);

        $this->audience($store, $campaign->audience)->select('id', 'email')->orderBy('id')
            ->chunkById(500, function ($customers) use ($campaign) {
                $rows = $customers->unique(fn ($c) => mb_strtolower($c->email))->map(fn ($c) => [
                    'store_id' => $campaign->store_id,
                    'email_campaign_id' => $campaign->id,
                    'customer_id' => $c->id,
                    'email' => mb_strtolower($c->email),
                    'token' => Str::random(40),
                    'status' => 'queued',
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all();
                DB::table('email_campaign_recipients')->insertOrIgnore($rows);
            });

        $total = EmailCampaignRecipient::withoutGlobalScope('store')->where('email_campaign_id', $campaign->id)->count();
        $campaign->forceFill(['recipients_count' => $total])->save();
        if ($total === 0) {
            $campaign->forceFill(['status' => 'sent', 'sent_at' => now()])->save();

            return;
        }

        EmailCampaignRecipient::withoutGlobalScope('store')->where('email_campaign_id', $campaign->id)
            ->orderBy('id')->select('id')->chunkById(self::BATCH, function ($batch) use ($campaign) {
                SendCampaignBatch::dispatch($campaign->id, $batch->pluck('id')->all());
            });
    }

    /** Send one batch (called by the queued job). */
    public function sendBatch(int $campaignId, array $recipientIds): void
    {
        $campaign = EmailCampaign::withoutGlobalScope('store')->find($campaignId);
        if (! $campaign) {
            return;
        }
        $store = Store::findOrFail($campaign->store_id);
        $recipients = EmailCampaignRecipient::withoutGlobalScope('store')->with('customer')
            ->whereIn('id', $recipientIds)->where('status', 'queued')->get();

        foreach ($recipients as $r) {
            // Unsubscribed between scheduling and sending: skip
            if (! $r->customer?->accepts_marketing) {
                $r->forceFill(['status' => 'failed', 'error' => 'unsubscribed'])->save();
                $campaign->increment('failed_count');

                continue;
            }
            try {
                $this->deliver($campaign, $store, $r);
                $r->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
                $campaign->increment('sent_count');
            } catch (Throwable $e) {
                report($e);
                $r->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300)])->save();
                $campaign->increment('failed_count');
            }
        }

        $left = EmailCampaignRecipient::withoutGlobalScope('store')->where('email_campaign_id', $campaignId)->where('status', 'queued')->exists();
        if (! $left) {
            EmailCampaign::withoutGlobalScope('store')->whereKey($campaignId)->where('status', 'sending')->update(['status' => 'sent', 'sent_at' => now()]);
        }
    }

    /** A test copy to the merchant (tracking and unsubscribe links are inert). */
    public function sendTest(EmailCampaign $campaign, Store $store, string $to): void
    {
        $fake = new EmailCampaignRecipient(['email' => $to, 'token' => 'test']);
        Mail::to($to)->send(new CampaignMail($campaign, $store, $fake, test: true));
    }

    public function deliver(EmailCampaign $campaign, Store $store, EmailCampaignRecipient $r): void
    {
        $locale = $r->customer?->locale ?: \App\I18n\Localization::storeDefault($store);
        app(\App\I18n\Localization::class)->within($store, $locale, function () use ($campaign, $store, $r) {
            app(\App\Promotions\Promotions::class)->enableFor($store);   // sale prices in product blocks
            Mail::to($r->email)->send(new CampaignMail($campaign, $store, $r));
        });
    }

    /** Signature for tracked links, so /email/click can't be used as an open redirect. */
    public static function linkSignature(string $token, string $url): string
    {
        return substr(hash_hmac('sha256', $token.'|'.$url, (string) config('app.key')), 0, 20);
    }

    /** Due scheduled campaigns (command, every minute). */
    public function startDue(): int
    {
        $n = 0;
        foreach (EmailCampaign::withoutGlobalScope('store')->where('status', 'scheduled')->where('scheduled_at', '<=', now())->get() as $c) {
            $store = Store::find($c->store_id);
            if ($store && $this->audience($store, $c->audience)->count() > app(SendQuota::class)->remaining($store)) {
                $c->forceFill(['status' => 'draft', 'scheduled_at' => null])->save();   // over this month's allowance: back to draft
                report(new \RuntimeException("Campaign {$c->id} not sent: over the monthly email allowance"));

                continue;
            }
            $this->start($c);
            $n++;
        }

        return $n;
    }
}
