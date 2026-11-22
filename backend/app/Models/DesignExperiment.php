<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/** A/B test of a store design: the live one (A) against a draft (B), for the main site or a market (App\Themes\DesignExperiments). */
class DesignExperiment extends Model
{
    use BelongsToStore;

    protected $guarded = ['id', 'store_id'];

    protected function casts(): array
    {
        return ['variant' => 'array', 'started_at' => 'datetime', 'ended_at' => 'datetime'];
    }
}
