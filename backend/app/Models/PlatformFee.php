<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/** Transaction-fee ledger: +fee when an order is paid, −credit when it is refunded. */
class PlatformFee extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'order_id', 'type', 'rate_bp', 'base_amount', 'amount', 'currency', 'invoice_id', 'collected_at'];

    protected function casts(): array
    {
        return ['rate_bp' => 'integer', 'base_amount' => 'integer', 'amount' => 'integer', 'collected_at' => 'datetime'];
    }
}
