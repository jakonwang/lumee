<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class TaxRate extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'country', 'region', 'name', 'rate'];

    protected function casts(): array
    {
        return ['rate' => 'float'];
    }
}
