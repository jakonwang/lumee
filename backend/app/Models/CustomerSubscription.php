<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A buyer's subscribe-and-save subscription (see App\Subscriptions\Subscriptions). */
class CustomerSubscription extends Model
{
    use BelongsToStore;

    protected $guarded = ['id'];

    protected $hidden = ['stripe_customer', 'stripe_payment_method'];

    protected function casts(): array
    {
        return [
            'items' => 'array', 'shipping_address' => 'array', 'shipping_method' => 'array', 'discount' => 'float',
            'next_billing_at' => 'datetime', 'cancelled_at' => 'datetime', 'failed_attempts' => 'integer', 'orders_count' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'subscription_id');
    }
}
