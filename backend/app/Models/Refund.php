<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'store_id', 'order_id', 'user_id', 'amount', 'shipping_amount', 'reason', 'restock', 'gateway', 'reference', 'items',
    ];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'shipping_amount' => 'integer', 'restock' => 'boolean', 'items' => 'array'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
