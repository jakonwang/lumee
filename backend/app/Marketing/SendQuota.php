<?php

namespace App\Marketing;

use App\Models\EmailCampaignRecipient;
use App\Models\Store;

/** Monthly marketing email allowance per plan (campaigns + automated flows). Order emails don't count. */
class SendQuota
{
    public function limit(Store $store): int
    {
        return (int) app(\App\Billing\Billing::class)->limits($store)['marketing_emails'];
    }

    public function used(Store $store): int
    {
        return EmailCampaignRecipient::withoutGlobalScope('store')->where('store_id', $store->id)
            ->where('status', 'sent')->where('sent_at', '>=', now()->startOfMonth())->count();
    }

    public function remaining(Store $store): int
    {
        return max(0, $this->limit($store) - $this->used($store));
    }

    public function summary(Store $store): array
    {
        return ['used' => $this->used($store), 'limit' => $this->limit($store), 'resets_at' => now()->startOfMonth()->addMonth()->toDateString()];
    }
}
