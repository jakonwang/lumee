<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * List view of a product. Expects variants loaded (cheap: price/stock columns only).
 *
 * @mixin \App\Models\Product
 */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $variants = $this->variants;
        $min = $variants->min('price');
        $max = $variants->max('price');
        $first = $variants->first();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'handle' => $this->handle,
            'status' => $this->status,
            'product_type' => $this->product_type,
            'featured_image' => $this->featured_image,
            'price' => Money::format($min),
            'price_max' => Money::format($max),
            'compare_at_price' => $first?->compare_at_price ? Money::format($first->compare_at_price) : null,
            'inventory' => (int) $variants->sum('inventory_quantity'),
            'variants_count' => $variants->count(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
