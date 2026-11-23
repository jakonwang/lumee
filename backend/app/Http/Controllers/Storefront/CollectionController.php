<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Product;
use App\Tenancy\CurrentStore;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class CollectionController extends Controller
{
    /** Shopper-facing sort options (key → label). */
    public const SORTS = [
        'manual' => 'Featured',
        'newest' => 'Newest',
        'price_asc' => 'Price: Low to High',
        'price_desc' => 'Price: High to Low',
        'title' => 'Alphabetical',
    ];

    public function index(CurrentStore $current): View
    {
        $collections = Collection::published()->with('image')->withCount(['products' => fn ($q) => $q->active()])
            ->orderBy('title')->get()->filter(fn ($c) => $c->products_count > 0)->values();

        return view($current->themeView('collections'), compact('collections'));
    }

    public function show(Request $request, CurrentStore $current, string $handle): View
    {
        $sort = array_key_exists((string) $request->query('sort'), self::SORTS) ? $request->query('sort') : null;

        if ($handle === 'all') {
            // Virtual "all products" collection
            $collection = new Collection(['title' => __('All products'), 'handle' => 'all', 'sort_order' => 'newest']);
            $query = Collection::applySort(Product::active(), $sort ?? 'newest');
        } else {
            $collection = Collection::published()->where('handle', $handle)->firstOrFail();
            $query = $collection->sortedProducts($sort);
        }

        $filters = app(\App\Catalog\CollectionFilters::class)->apply($query, $request);   // narrows $query
        $products = $query
            ->with('variants:id,product_id,price,compare_at_price,inventory_quantity,position')
            ->paginate(24)
            ->withQueryString();

        return view($current->themeView('collection'), [
            'collection' => $collection,
            'products' => $products,
            'sort' => $sort ?? $collection->sort_order,
            'filters' => $filters,
            'clearFilters' => \App\Catalog\CollectionFilters::clearUrl($request),
            'sorts' => $handle === 'all' ? array_diff_key(self::SORTS, ['manual' => 1]) : self::SORTS,
        ]);
    }
}
