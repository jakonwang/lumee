<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShippingZone extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'name', 'countries', 'position'];

    protected function casts(): array
    {
        return ['countries' => 'array'];
    }

    public function rates(): HasMany
    {
        return $this->hasMany(ShippingRate::class, 'zone_id')->orderBy('position');
    }

    public function isRestOfWorld(): bool
    {
        return empty($this->countries);
    }
}
