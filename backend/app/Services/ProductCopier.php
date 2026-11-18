<?php

namespace App\Services;

use App\Currency\ExchangeRates;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Store;
use App\Support\Money;
use App\Tenancy\CurrentStore;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Copy products from one store to another the same user manages: options, variants, SKUs,
 * prices (as-is or converted to the target currency), optional stock, and the image files
 * themselves (each store owns its files, so deleting in one never breaks the other).
 * The copy is saved through ProductService as the target store, so the usual rules apply.
 */
class ProductCopier
{
    public const MAX = 100;

    public function __construct(private ProductService $products, private CurrentStore $current) {}

    /**
     * @param  array{price_mode?: 'same'|'convert', inventory?: bool, images?: bool, status?: 'draft'|'same'}  $opts
     * @return array<int, array{source_id:int, product_id:?int, title:string, error:?string}>
     */
    public function copy(Store $source, Store $target, array $productIds, array $opts): array
    {
        $rate = 1.0;
        if (($opts['price_mode'] ?? 'same') === 'convert' && $source->currency !== $target->currency) {
            $rate = app(ExchangeRates::class)->rate($source->currency, $target->currency)
                ?? throw ValidationException::withMessages(['price_mode' => __('暂时没有 :p0 到 :p1 的汇率，请选择「价格不变」', ['p0' => $source->currency, 'p1' => $target->currency])]);
        }

        $products = Product::withoutGlobalScope('store')->where('store_id', $source->id)->whereIn('id', $productIds)
            ->with(['images' => fn ($q) => $q->withoutGlobalScope('store')->orderBy('position'),
                'options' => fn ($q) => $q->withoutGlobalScope('store')->orderBy('position'),
                'variants' => fn ($q) => $q->withoutGlobalScope('store')->orderBy('position')])
            ->get()->sortBy(fn ($p) => array_search($p->id, $productIds))->values();

        $previous = $this->current->check() ? $this->current->getOrFail() : null;
        $results = [];
        try {
            $this->current->set($target);   // everything below is created in the target store
            foreach ($products as $p) {
                try {
                    $copy = $this->copyOne($p, $target, $rate, $opts);
                    $results[] = ['source_id' => $p->id, 'product_id' => $copy->id, 'title' => $p->title, 'error' => null];
                    app(\App\Webhooks\Webhooks::class)->productChanged($copy->id, 'products/create');
                } catch (Throwable $e) {
                    report($e);
                    $results[] = ['source_id' => $p->id, 'product_id' => null, 'title' => $p->title,
                        'error' => $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : __('复制失败')];
                }
            }
        } finally {
            $this->current->set($previous);
        }

        return $results;
    }

    private function copyOne(Product $p, Store $target, float $rate, array $opts): Product
    {
        $imageMap = [];
        $images = [];
        if ($opts['images'] ?? true) {
            foreach ($p->images as $img) {
                $copy = $this->copyImage($img, $target);
                if ($copy) {
                    $imageMap[$img->id] = $copy->id;
                    $images[] = ['id' => $copy->id, 'alt' => $img->alt];
                }
            }
        }
        $price = fn (?int $minor) => $minor === null ? null : Money::format((int) round($minor * $rate));

        $data = [
            'title' => $p->getRawOriginal('title'),
            'handle' => $p->handle,
            'description' => $p->getRawOriginal('description'),
            'status' => ($opts['status'] ?? 'draft') === 'same' ? $p->status : 'draft',
            'product_type' => $p->getRawOriginal('product_type'),
            'vendor' => $p->vendor,
            'tags' => $p->tags ?? [],
            'taxable' => (bool) $p->taxable,
            'seo' => $p->getRawOriginal('seo') ? json_decode($p->getRawOriginal('seo'), true) : null,
            'images' => $images,
            'options' => $p->options->map(fn ($o) => ['name' => $o->getRawOriginal('name'), 'values' => json_decode((string) $o->getRawOriginal('values'), true) ?: []])->values()->all(),
            'variants' => $p->variants->map(fn ($v) => [
                'options' => json_decode((string) $v->getRawOriginal('options'), true) ?: [],
                'price' => $price((int) $v->getRawOriginal('price')),
                'compare_at_price' => $price($v->getRawOriginal('compare_at_price') !== null ? (int) $v->getRawOriginal('compare_at_price') : null),
                'sku' => $v->sku,
                'inventory' => ($opts['inventory'] ?? false) ? (int) $v->inventory_quantity : 0,
                'weight' => $v->weight,
                'image_id' => $v->image_id ? ($imageMap[$v->image_id] ?? null) : null,
            ])->values()->all(),
        ];

        return \App\Services\Inventory::as('copy', fn () => $this->products->save(null, $data));
    }

    /** Duplicate the file into the target store's folder and register it as an unattached upload. */
    private function copyImage(ProductImage $img, Store $target): ?ProductImage
    {
        $disk = Storage::disk($img->disk);
        if (! $disk->exists($img->path)) {
            return null;
        }
        $ext = pathinfo($img->path, PATHINFO_EXTENSION) ?: 'jpg';
        $path = "stores/{$target->id}/products/".Str::uuid().'.'.$ext;
        $disk->copy($img->path, $path);
        try {
            $disk->setVisibility($path, 'public');
        } catch (Throwable) {
        }

        return ProductImage::create([
            'disk' => $img->disk, 'path' => $path, 'alt' => $img->alt,
            'width' => $img->width, 'height' => $img->height, 'size' => $img->size,
        ]);
    }
}
