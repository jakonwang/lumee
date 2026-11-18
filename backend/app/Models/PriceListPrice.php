<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceListPrice extends Model
{
    public $timestamps = false;

    protected $fillable = ['price_list_id', 'variant_id', 'price', 'min_qty', 'step', 'tiers'];

    protected function casts(): array
    {
        return ['price' => 'integer', 'min_qty' => 'integer', 'step' => 'integer', 'tiers' => 'array'];
    }
}
