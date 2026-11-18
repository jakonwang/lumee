<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class B2bApplication extends Model
{
    use BelongsToStore;

    protected $table = 'b2b_applications';

    protected $guarded = ['id', 'store_id'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }
}
