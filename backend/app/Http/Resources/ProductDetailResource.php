<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full product for the editor: images, options, variants.
 *
 * @mixin \App\Models\Product
 */
class ProductDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...(new ProductResource($this->resource))->toArray($request),
            'description' => $this->description,
            'vendor' => $this->vendor,
            'tags' => $this->tags ?? [],
            'taxable' => (bool) ($this->taxable ?? true),
            'is_gift_card' => (bool) $this->is_gift_card,
            'b2b_only' => (bool) $this->b2b_only,
            'size_chart' => $this->size_chart,
            'supplier_id' => $this->supplier_id,
            'stock_alerts' => \App\Models\StockAlert::where('product_id', $this->id)->whereNull('notified_at')->count(),
            'subscription' => \App\Subscriptions\Plans::config($this->resource),
            'preorder' => \App\Catalog\Preorders::config($this->resource),
            'digital' => (bool) $this->digital,
            'upsells' => \App\Models\Product::whereIn('id', (array) ($this->upsell_ids ?? []))->get(['id', 'title', 'featured_image'])
                ->sortBy(fn ($p) => array_search($p->id, (array) $this->upsell_ids))->values()->map(fn ($p) => ['id' => $p->id, 'title' => $p->title, 'featured_image' => $p->featured_image]),
            'files' => \App\Models\ProductFile::where('product_id', $this->id)->orderBy('id')->get()->map->toApi()->values(),
            'seo' => ['title' => $this->seo['title'] ?? null, 'description' => $this->seo['description'] ?? null],
            'images' => $this->images->map->toApi()->values(),
            'collection_ids' => $this->collections->pluck('id')->values(),
            'options' => $this->options->map(fn ($o) => ['name' => $o->name, 'values' => $o->values])->values(),
            'variants' => $this->variants->map(fn ($v) => [
                'id' => $v->id,
                'title' => $v->title,
                'options' => $v->options ?? (object) [],
                'price' => Money::format($v->price),
                'compare_at_price' => $v->compare_at_price !== null ? Money::format($v->compare_at_price) : null,
                'sku' => $v->sku,
                'inventory' => $v->inventory_quantity,
                'weight' => $v->weight,
                'image_id' => $v->image_id,
            ])->values(),
        ];
    }
}
