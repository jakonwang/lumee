<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductDetailResource;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function __construct(private ProductService $products) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $products = Product::query()
            ->with('variants:id,product_id,price,compare_at_price,inventory_quantity,position')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('q'), fn ($q, $term) => $q->where('title', 'like', "%{$term}%"))
            ->latest('id')
            ->paginate(min((int) $request->query('per_page', 20), 100));

        return ProductResource::collection($products);
    }

    public function store(Request $request)
    {
        $product = $this->products->save(null, $this->validated($request, creating: true));
        app(\App\Webhooks\Webhooks::class)->productChanged($product->id, 'products/create');

        return (new ProductDetailResource($product))->response()->setStatusCode(201);
    }

    public function show(Product $product): ProductDetailResource
    {
        return new ProductDetailResource($product->load('images', 'options', 'variants', 'collections'));
    }

    public function update(Request $request, Product $product): ProductDetailResource
    {
        $saved = $this->products->save($product->load('options', 'collections'), $this->validated($request, creating: false));
        app(\App\Webhooks\Webhooks::class)->productChanged($saved->id, 'products/update');

        return new ProductDetailResource($saved);
    }

    public function destroy(Product $product)
    {
        // Delete images one by one so their files are removed too
        $product->images()->get()->each->delete();
        $product->delete();
        app(\App\Webhooks\Webhooks::class)->productChanged($product->id, 'products/delete', ['store_id' => $product->store_id, 'title' => $product->title]);

        return response()->noContent();
    }

    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'title' => [$req, 'string', 'max:255'],
            'handle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'seo' => ['sometimes', 'nullable', 'array'],
            'seo.title' => ['nullable', 'string', 'max:70'],
            'seo.description' => ['nullable', 'string', 'max:320'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'status' => ['sometimes', 'nullable', 'in:active,draft,archived'],
            'taxable' => ['sometimes', 'boolean'],
            'is_gift_card' => ['sometimes', 'boolean'],
            'b2b_only' => ['sometimes', 'boolean'],
            'supplier_id' => ['sometimes', 'nullable', 'integer'],
            'size_chart' => ['sometimes', 'nullable', \Illuminate\Validation\Rule::in(\App\Catalog\SizeCharts::TYPES)],   // US sizes → each market's system
            'digital' => ['sometimes', 'boolean'],
            'upsell_ids' => ['sometimes', 'nullable', 'array', 'max:3'],
            'upsell_ids.*' => ['integer'],
            'preorder' => ['sometimes', 'nullable', 'array'],
            'preorder.enabled' => ['boolean'],
            'preorder.ships_at' => ['nullable', 'date', 'after:today'],
            'preorder.note' => ['nullable', 'string', 'max:200'],
            'preorder.limit' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'subscription' => ['sometimes', 'nullable', 'array'],
            'subscription.enabled' => ['boolean'],
            'subscription.discount' => ['nullable', 'numeric', 'min:0', 'max:90'],
            'subscription.intervals' => ['array'],
            'subscription.intervals.*' => ['string', 'in:'.implode(',', \App\Subscriptions\Plans::INTERVALS)],
            'subscription.only' => ['boolean'],
            'product_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'vendor' => ['sometimes', 'nullable', 'string', 'max:100'],
            'tags' => ['sometimes', 'nullable', 'array', 'max:50'],
            'tags.*' => ['string', 'max:50'],

            'images' => ['sometimes', 'array', 'max:20'],
            'images.*.id' => ['required', 'integer'],
            'images.*.alt' => ['nullable', 'string', 'max:255'],

            'options' => ['sometimes', 'array', 'max:3'],
            'options.*.name' => ['required', 'string', 'max:64'],
            'options.*.values' => ['required', 'array', 'min:1', 'max:'.\App\Models\Product::MAX_VARIANTS],
            'options.*.values.*' => ['required', 'string', 'max:100'],   // as collected (App\Collect\Item)

            'variants' => ['sometimes', 'array', 'min:1', 'max:'.\App\Models\Product::MAX_VARIANTS],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.options' => ['nullable', 'array'],
            'variants.*.price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'variants.*.compare_at_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'variants.*.sku' => ['nullable', 'string', 'max:100'],
            'variants.*.inventory' => ['nullable', 'integer', 'min:-100000', 'max:1000000'],
            'variants.*.image_id' => ['nullable', 'integer'],
            'variants.*.weight' => ['nullable', 'integer', 'min:0', 'max:1000000'],

            'collection_ids' => ['sometimes', 'array', 'max:100'],
            'collection_ids.*' => ['integer'],

            // Legacy single-variant fields
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'compare_at_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'inventory' => ['sometimes', 'nullable', 'integer'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:100'],
            'weight' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
        ], [
            'variants.*.price.required' => __('请填写每个 SKU 的价格'),
        ]);
    }
}
