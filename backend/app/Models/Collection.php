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
                ->addSelect(['min_price' => self::minPriceQuery()])
                ->orderBy('min_price', $sort === 'price_asc' ? 'asc' : 'desc')->orderBy('products.id'),
            // manual: pivot position (only meaningful on a collection relation)
            default => method_exists($query, 'orderByPivot') ? $query->orderByPivot('position')->orderBy('products.id') : $query->orderByDesc('products.id'),
        };
    }

    /**
     * A product's lowest price as a sort key. Inside a market, a variant with a fixed market
     * price counts that price converted back to the store currency (market prices are otherwise
     * the store price times one factor, so ordering on store-currency values is the same order
     * shoppers see — up to price-ending ties).
     */
    private static function minPriceQuery()
    {
        $variants = ProductVariant::withoutGlobalScope('store')->whereColumn('product_variants.product_id', 'products.id');
        $markets = app(\App\Markets\MarketContext::class);
        if (! $markets->active()) {
            return $variants->selectRaw('MIN(price)');
        }
        $factor = $markets->rate() * (1 + $markets->market()->price_adjustment / 100);
        if ($factor <= 0) {
            return $variants->selectRaw('MIN(price)');
        }

        return $variants
            ->leftJoin('market_prices', fn ($j) => $j->on('market_prices.variant_id', '=', 'product_variants.id')
                ->where('market_prices.market_id', $markets->market()->id))
            ->selectRaw('MIN(COALESCE(market_prices.price / ?, product_variants.price))', [$factor]);
    }

    /** Image to show: the collection's own, else its first product's. */
    public function imageUrl(): ?string
    {
        return $this->image?->url()
            ?? $this->sortedProducts()->whereNotNull('featured_image')->value('featured_image');
    }
}
