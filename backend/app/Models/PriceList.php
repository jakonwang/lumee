<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Wholesale / B2B pricing for customers with a tag: a % off everything and/or fixed variant prices. */
class PriceList extends Model
{
    use BelongsToStore;

    protected $fillable = ['name', 'customer_tag', 'percent_off', 'min_subtotal', 'enabled'];

    protected function casts(): array
    {
        return ['percent_off' => 'float', 'min_subtotal' => 'integer', 'enabled' => 'boolean'];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(PriceListPrice::class);
    }
}
