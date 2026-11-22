<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/** A/B test of a product's main image or title (App\Testing\Experiments). */
class ContentExperiment extends Model
{
    use BelongsToStore;

    protected $fillable = ['product_id', 'kind', 'b_value', 'status', 'winner', 'started_at', 'ended_at'];

    protected function casts(): array
    {
        return ['a_views' => 'integer', 'a_carts' => 'integer', 'b_views' => 'integer', 'b_carts' => 'integer',
            'started_at' => 'datetime', 'ended_at' => 'datetime'];
    }
}
