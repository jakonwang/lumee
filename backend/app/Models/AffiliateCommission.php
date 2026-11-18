<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class AffiliateCommission extends Model
{
    use BelongsToStore;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['base_amount' => 'integer', 'amount' => 'integer', 'rate' => 'float', 'paid_at' => 'datetime'];
    }
}
