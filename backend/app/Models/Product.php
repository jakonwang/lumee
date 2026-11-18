<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    use BelongsToStore, \App\I18n\HasTranslations;

    public const TRANSLATION_TYPE = 'product';

    public const TRANSLATABLE = ['title', 'description', 'product_type', 'seo'];

    protected $fillable = [
        'title', 'handle', 'description', 'status', 'vendor',
        'product_type', 'tags', 'featured_image', 'seo', 'taxable', 'is_gift_card', 'subscription', 'b2b_only', 'digital',
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

    /** On sale in the storefront. B2B-only products are hidden unless the signed-in buyer is a business buyer. */
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->when(! app(\App\B2B\B2B::class)->isBusiness(), fn ($q) => $q->where($q->getModel()->getTable().'.b2b_only', false));
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
