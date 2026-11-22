<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/** Cost → selling price for collected products (App\Collect\Pricing). */
class PricingRule extends Model
{
    use BelongsToStore;

    public const SCOPES = ['all', 'product_type', 'tag'];

    protected $fillable = ['name', 'scope', 'scope_value', 'multiplier', 'add_amount', 'compare_multiplier', 'rounding', 'position'];

    protected function casts(): array
    {
        return ['multiplier' => 'float', 'add_amount' => 'integer', 'compare_multiplier' => 'float', 'position' => 'integer'];
    }
}
