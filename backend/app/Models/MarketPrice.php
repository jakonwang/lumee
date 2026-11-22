<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/** A fixed variant price in a market's currency (minor units). */
class MarketPrice extends Model
{
    use BelongsToStore;

    protected $fillable = ['market_id', 'variant_id', 'price', 'compare_at_price'];

    protected function casts(): array
    {
        return ['price' => 'integer', 'compare_at_price' => 'integer'];
    }
}
