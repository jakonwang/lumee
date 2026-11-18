<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class Translation extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'type', 'resource_id', 'locale', 'field', 'value'];
}
