<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class StockAlert extends Model
{
    use BelongsToStore;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['notified_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
