<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/** A shareable wholesale catalog link (+ JSON / CSV feed) for prospects and distributors. */
class WholesaleCatalog extends Model
{
    use BelongsToStore;

    protected $fillable = ['name', 'token', 'market_id', 'price_list_id', 'collection_id', 'lead_id', 'show_stock', 'expires_at'];

    protected function casts(): array
    {
        return ['show_stock' => 'boolean', 'expires_at' => 'datetime', 'last_viewed_at' => 'datetime', 'views' => 'integer'];
    }

    protected $hidden = ['order_key'];

    public function isLive(): bool
    {
        return ! $this->expires_at || $this->expires_at->isFuture();
    }
}
