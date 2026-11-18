<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/** A store's platform plan subscription (one per store). */
class Subscription extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'store_id', 'plan', 'interval', 'status', 'provider', 'current_period_end', 'cancel_at_period_end',
        'past_due_since', 'ended_at', 'stripe_customer_id', 'stripe_subscription_id', 'note',
    ];

    protected function casts(): array
    {
        return [
            'current_period_end' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'past_due_since' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
