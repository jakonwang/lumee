<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Product;
use App\Tenancy\CurrentStore;
use App\Themes\ThemeContext;
use Illuminate\Contracts\View\View;

/*
 * NOTE: request-scoped services (CurrentStore, CartService, ThemeContext) are injected per method,
 * never via the constructor — Laravel may reuse a controller instance across requests.
 */
class StorefrontController extends Controller
{
    public function home(CurrentStore $current): View
    {
        // No store on this host → platform marketing landing page
        if (! $current->check()) {
            return view('platform.welcome');
        }

        $sections = collect(app(ThemeContext::class)->sections());
        $variantCols = 'variants:id,product_id,price,compare_at_price,inventory_quantity,position';

        // Featured products: newest products, or a chosen collection's products (per section)
        $featured = $sections->where('type', 'featured_products');
        $newestLimit = $featured->filter(fn ($s) => ! $s['settings']['collection'])->max(fn ($s) => $s['settings']['limit']) ?? 0;
        $products = $newestLimit > 0
            ? Product::active()->with($variantCols)->latest('id')->take($newestLimit)->get()
            : collect();

        $sectionProducts = [];
        $sectionCollections = [];
        foreach ($featured->filter(fn ($s) => $s['settings']['collection']) as $s) {
            $collection = Collection::published()->find($s['settings']['collection']);
            if ($collection) {
                $sectionCollections[$s['id']] = $collection;
                $sectionProducts[$s['id']] = $collection->sortedProducts()->with($variantCols)->take($s['settings']['limit'])->get();
            }
        }

        // Collection list: the chosen collections in order, else the first 4 published ones
        foreach ($sections->where('type', 'collection_list') as $s) {
            $ids = array_values(array_filter([1, 2, 3, 4], fn ($i) => $s['settings']["collection_$i"]));
            $ids = array_map(fn ($i) => $s['settings']["collection_$i"], $ids);
            $sectionCollections[$s['id']] = $ids
                ? Collection::published()->with('image')->whereIn('id', $ids)->get()->sortBy(fn ($c) => array_search($c->id, $ids))->values()
                : Collection::published()->with('image')->orderBy('title')->take(4)->get();
        }

        return view($current->themeView('home'), compact('products', 'sectionProducts', 'sectionCollections'));
    }

    public function product(CurrentStore $current, string $handle): View
    {
        $product = Product::active()->with('variants', 'images', 'options')->where('handle', $handle)->firstOrFail();
        app(\App\Analytics\Visits::class)->productView($product->id);
        if ($viewer = \App\Customers\CustomerSession::user()) {   // browse-abandonment emails
            \App\Models\Customer::withoutGlobalScope('store')->whereKey($viewer->id)->update(['last_viewed_product_id' => $product->id, 'last_viewed_at' => now()]);
        }

        $reviewSettings = \App\Reviews\Reviews::settings($current->getOrFail());
        $reviews = $reviewSettings['enabled']
            ? $product->reviews()->published()->latest()->paginate(10, ['*'], 'reviews_page')->fragment('reviews')
            : null;
        $ratingBreakdown = $reviewSettings['enabled'] && $product->rating_count
            ? $product->reviews()->published()->selectRaw('rating, COUNT(*) as n')->groupBy('rating')->pluck('n', 'rating')->all()
            : [];

        // "You may also like": same collections first, then newest; never the product itself
        $collectionIds = $product->collections()->pluck('collections.id');
        $cols = 'variants:id,product_id,price,compare_at_price,inventory_quantity,position';
        $related = $collectionIds->isEmpty() ? collect() : Product::active()->with($cols)->whereKeyNot($product->id)
            ->whereHas('collections', fn ($q) => $q->whereIn('collections.id', $collectionIds))->latest('id')->limit(4)->get();
        if ($related->count() < 4) {
            $related = $related->concat(Product::active()->with($cols)->whereKeyNot($product->id)
                ->whereNotIn('id', $related->pluck('id'))->latest('id')->limit(4 - $related->count())->get());
        }

        $together = app(\App\Catalog\Upsells::class)->forProduct($product);
        $related = $related->whereNotIn('id', $together->pluck('id'))->values();

        return view($current->themeView('product'), compact('product', 'reviews', 'reviewSettings', 'ratingBreakdown', 'related', 'together'));
    }

    /** "Email me when it's back" on a sold-out variant. */
    /** An affiliate's private stats page (link from the merchant). */
    public function affiliate(string $token): View
    {
        $a = \App\Models\Affiliate::where('token', $token)->firstOrFail();   // store-scoped
        $store = app(\App\Tenancy\CurrentStore::class)->getOrFail();

        return view('storefront.affiliate', [
            'store' => $store, 'affiliate' => $a, 'settings' => \App\Marketing\Affiliates::settings($store),
            'stats' => \App\Marketing\Affiliates::stats($store, $a), 'link' => $store->storefrontUrl().'/?ref='.$a->code,
            'commissions' => \App\Models\AffiliateCommission::where('affiliate_id', $a->id)->latest('id')->limit(50)->get(),
            'money' => fn (int $c) => \App\Support\Money::display($c, $store->currency),
        ]);
    }

    /** Digital product download (counted; limited number of times, expires). */
    public function download(string $token): \Symfony\Component\HttpFoundation\Response
    {
        $d = \App\Models\OrderDownload::with('file')->where('token', $token)->firstOrFail();   // store-scoped
        $order = \App\Models\Order::find($d->order_id);
        abort_unless($d->file && $order && $order->isPaid() && ! $order->isCancelled(), 404);
        if (! $d->usable()) {
            abort(410, __('Download links work :count times for :days days. Contact the store for a new link.', ['count' => $d->max_downloads, 'days' => \App\Catalog\DigitalDelivery::DAYS]));
        }
        $d->forceFill(['downloads' => $d->downloads + 1, 'last_downloaded_at' => now()])->save();

        return \Illuminate\Support\Facades\Storage::disk('local')->download($d->file->path, $d->file->filename);
    }

    public function notifyMe(\Illuminate\Http\Request $request, string $handle): \Illuminate\Http\RedirectResponse
    {
        $product = Product::active()->where('handle', $handle)->firstOrFail();
        $data = $request->validate(['variant_id' => ['required', 'integer'], 'email' => ['required', 'email', 'max:255']]);
        $variant = $product->variants()->findOrFail($data['variant_id']);
        if ($variant->inventory_quantity > 0) {
            return back()->with('status', __('Good news — this is in stock now.'));
        }
        app(\App\Marketing\StockAlerts::class)->subscribe($variant, $data['email'], \App\Customers\CustomerSession::user()?->id, app()->getLocale());

        return back()->with('notify_status', __('We’ll email you as soon as it’s back in stock.'));
    }

    /** Wishlist page: the list lives in the shopper's browser (localStorage); this renders it. */
    public function wishlist(CurrentStore $current): View
    {
        return view($current->themeView('wishlist'));
    }

    /** Cards for the saved handles (active products only, max 60). */
    public function wishlistProducts(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $handles = array_slice(array_filter(array_map('trim', explode(',', (string) $request->query('handles')))), 0, 60);
        $px = app(\App\Currency\Presentment::class);
        $products = $handles ? Product::active()->with('variants:id,product_id,price,inventory_quantity')->whereIn('handle', $handles)->get() : collect();

        return response()->json(['data' => $products->map(fn ($p) => [
            'handle' => $p->handle,
            'title' => $p->title,
            'url' => route('store.product', $p->handle, false),
            'image' => $p->featured_image,
            'price' => $px->display((int) $p->variants->min('price')),
            'sold_out' => $p->variants->sum('inventory_quantity') <= 0,
        ])->values()]);
    }
}
