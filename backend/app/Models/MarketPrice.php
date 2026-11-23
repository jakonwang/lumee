<?php

namespace App\Models;

use App\Catalog\PriceHistory;
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

    protected static function booted(): void
    {
        // EU "lowest price of the last 30 days" also covers prices fixed per market
        static::saved(fn (MarketPrice $p) => $p->wasRecentlyCreated || $p->wasChanged('price') ? PriceHistory::recordMarket($p) : null);
        static::deleted(fn (MarketPrice $p) => PriceHistory::recordMarket($p, true));
    }
}
