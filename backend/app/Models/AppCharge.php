<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A charge an app asked a store to approve: recurring (every 30 days) or one-time, in USD cents.
 * Not store-scoped through the global scope; every query filters by store explicitly.
 */
class AppCharge extends Model
{
    public const INTERVALS = ['every_30_days', 'one_time'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price' => 'integer', 'trial_days' => 'integer', 'test' => 'boolean',
            'activated_at' => 'datetime', 'trial_ends_at' => 'datetime', 'billing_on' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    public function confirmationUrl(): string
    {
        return rtrim((string) config('lumee.admin_url'), '/').'/apps/charges/'.$this->token;
    }

    /** Shape returned to apps over the API. */
    public function toApi(): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'price' => number_format($this->price / 100, 2, '.', ''), 'currency' => 'USD',
            'interval' => $this->interval, 'trial_days' => $this->trial_days, 'test' => $this->test, 'status' => $this->status,
            'confirmation_url' => $this->status === 'pending' ? $this->confirmationUrl() : null, 'return_url' => $this->return_url,
            'activated_at' => $this->activated_at?->toIso8601String(), 'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'billing_on' => $this->billing_on?->toIso8601String(), 'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
