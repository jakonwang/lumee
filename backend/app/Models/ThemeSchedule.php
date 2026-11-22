<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/** A campaign version of the store design that goes live and comes down on its own (App\Themes\ThemeSchedules). */
class ThemeSchedule extends Model
{
    use BelongsToStore;

    protected $guarded = ['id', 'store_id'];

    protected function casts(): array
    {
        return ['config' => 'array', 'previous' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}
