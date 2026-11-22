<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingRate extends Model
{
    use BelongsToStore, \App\Markets\InMarketCurrency, \App\I18n\HasTranslations {
        \App\I18n\HasTranslations::getAttribute as translatedAttribute;
    }

    public const TRANSLATION_TYPE = 'shipping';

    public const TRANSLATABLE = ['name', 'description'];

    protected $fillable = [
        'store_id', 'zone_id', 'name', 'description', 'price',
        'min_subtotal', 'max_subtotal', 'min_weight', 'max_weight', 'position',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'min_subtotal' => 'integer',
            'max_subtotal' => 'integer',
            'min_weight' => 'integer',
            'max_weight' => 'integer',
        ];
    }

    /** Names translated per language; price and subtotal bounds in the market currency inside a market. */
    public function getAttribute($key)
    {
        return $this->inMarketCurrency($key, $this->translatedAttribute($key));
    }

    protected function marketMoneyAttributes(): array
    {
        return ['price', 'min_subtotal', 'max_subtotal'];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'zone_id');
    }

    /** Subtotal in minor units (after discounts), weight in grams. Bounds are inclusive at the bottom, exclusive at the top. */
    public function appliesTo(int $subtotal, int $weight = 0): bool
    {
        return ($this->min_subtotal === null || $subtotal >= $this->min_subtotal)
            && ($this->max_subtotal === null || $subtotal < $this->max_subtotal)
            && ($this->min_weight === null || $weight >= $this->min_weight)
            && ($this->max_weight === null || $weight < $this->max_weight);
    }
}
