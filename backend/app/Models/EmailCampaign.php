<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailCampaign extends Model
{
    use BelongsToStore;

    public const AUDIENCES = [
        'subscribers' => '全部订阅者',
        'customers' => '下过单的订阅者',
        'prospects' => '还没下过单的订阅者',
    ];

    protected $fillable = ['subject', 'preheader', 'heading', 'body', 'button_label', 'button_url', 'product_ids', 'discount_code', 'audience', 'status', 'scheduled_at', 'delay_hours'];

    protected function casts(): array
    {
        return [
            'product_ids' => 'array',
            'scheduled_at' => 'datetime',
            'delay_hours' => 'integer',
            'flow_position' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(EmailCampaignRecipient::class);
    }

    public function editable(): bool
    {
        return in_array($this->status, ['draft', 'scheduled', 'flow'], true);
    }

    /** Body Markdown → safe HTML (same renderer as pages). */
    public function html(): string
    {
        return (new Page(['body' => (string) $this->body]))->html();
    }
}
