<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/** How well a product suits a market. See App\Markets\MarketFit. */
class ProductMarketFit extends Model
{
    use BelongsToStore;

    protected $fillable = ['product_id', 'market_id', 'score', 'parts', 'reasons', 'tags', 'ai', 'ai_at'];

    protected function casts(): array
    {
        return ['parts' => 'array', 'reasons' => 'array', 'tags' => 'array', 'ai' => 'array', 'ai_at' => 'datetime', 'score' => 'integer'];
    }
}
