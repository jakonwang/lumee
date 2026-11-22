<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/** A product that is not sold in a market. */
class MarketProductExclusion extends Model
{
    use BelongsToStore;

    protected $fillable = ['market_id', 'product_id'];
}
