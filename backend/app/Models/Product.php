<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    use BelongsToStore, \App\I18n\HasTranslations;

    /** Most variants (SKUs) one product can have: marketplace listings often have 100+ (colour × phone model). */
    public const MAX_VARIANTS = 300;

    public const TRANSLATION_TYPE = 'product';

    public const TRANSLATABLE = ['title', 'description', 'product_type', 'seo'];

    protected $fillable = [
        'title', 'handle', 'description', 'status', 'vendor',
        'product_type', 'tags', 'featured_image', 'seo', 'taxable', 'is_gift_card', 'subscription', 'b2b_only', 'digital', 'size_chart', 'supplier_id',
    ];

    protected function casts(): array
    {
        return [
            'subscription' => 'array',
            'preorder' => 'array',
            'upsell_ids' => 'array',
            'digital' => 'boolean',
            'b2b_only' => 'boolean',
            'rating_avg' => 'float',
            'rating_count' => 'integer',
            'tags' => 'array',
            'seo' => 'array',
        ];
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position')->orderBy('id');
    }

    public function defaultVariant(): HasOne
    {
        return $this->hasOne(ProductVariant::class)->oldestOfMany('position');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position')->orderBy('id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class)->orderBy('position');
    }

    public function collections(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Collection::class)->withPivot('position');
    }

    /** On sale in the storefront. B2B-only products are hidden unless the signed-in buyer is a business buyer; products a market excludes are hidden there. */
    public function scopeActive($query)
    {
        $excluded = array_keys(app(\App\Markets\MarketContext::class)->excluded());   // not sold in this market

        return $query->where('status', 'active')
            ->when(! app(\App\B2B\B2B::class)->isBusiness(), fn ($q) => $q->where($q->getModel()->getTable().'.b2b_only', false))
            ->when($excluded, fn ($q) => $q->whereNotIn($q->getModel()->getTable().'.id', $excluded));
    }

    /** Sold to this shopper: active, and not excluded from the current market. */
    public function isSellable(): bool
    {
        return $this->status === 'active' && app(\App\Markets\MarketContext::class)->sells((int) $this->id);
    }

    public function hasOnlyDefaultVariant(): bool
    {
        return $this->options->isEmpty();
    }

    public function reviews(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProductReview::class);
    }
}
