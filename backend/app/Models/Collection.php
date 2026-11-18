<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Collection extends Model
{
    use BelongsToStore, \App\I18n\HasTranslations;

    public const TRANSLATION_TYPE = 'collection';

    public const TRANSLATABLE = ['title', 'description', 'seo'];

    public const SORTS = [
        'manual' => '手动排序',
        'newest' => '最新上架',
        'price_asc' => '价格从低到高',
        'price_desc' => '价格从高到低',
        'title' => '名称 A–Z',
    ];

    protected $fillable = ['title', 'handle', 'description', 'image_id', 'sort_order', 'published', 'seo'];

    protected function casts(): array
    {
        return ['published' => 'boolean', 'seo' => 'array', 'rules' => 'array'];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withPivot('position');
    }

    public function isSmart(): bool
    {
        return ! empty($this->rules);
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(StoreMedia::class, 'image_id');
    }

    public function scopePublished(Builder $q): Builder
    {
        return $q->where('published', true);
    }

    /** Active products of this collection in the requested (or the collection's default) order. */
    public function sortedProducts(?string $sort = null)
    {
        return self::applySort($this->products()->active(), $sort ?: $this->sort_order);
    }

    /** Works on any products query (also the virtual "all products" collection). */
    public static function applySort($query, string $sort)
    {
        return match ($sort) {
            'newest' => $query->orderByDesc('products.id'),
            'title' => $query->orderBy('products.title'),
            'price_asc', 'price_desc' => $query
                ->addSelect(['min_price' => ProductVariant::withoutGlobalScope('store')
                    ->selectRaw('MIN(price)')->whereColumn('product_variants.product_id', 'products.id')])
                ->orderBy('min_price', $sort === 'price_asc' ? 'asc' : 'desc'),
            // manual: pivot position (only meaningful on a collection relation)
            default => method_exists($query, 'orderByPivot') ? $query->orderByPivot('position')->orderBy('products.id') : $query->orderByDesc('products.id'),
        };
    }

    /** Image to show: the collection's own, else its first product's. */
    public function imageUrl(): ?string
    {
        return $this->image?->url()
            ?? $this->sortedProducts()->whereNotNull('featured_image')->value('featured_image');
    }
}
