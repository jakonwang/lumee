<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Support\Money;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates/updates a product together with its images, options and variants in one transaction.
 *
 * Payload (all keys optional on update):
 *   title, handle, description, status, product_type, vendor
 *   images:   [{id, alt?}]                       ordered; first = featured
 *   options:  [{name, values: [..]}]             max 3; empty = single default variant
 *   variants: [{id?, options: {Name: Value}, price, compare_at_price?, sku?, inventory, image_id?}]
 *   — or legacy flat fields for a single variant: price, compare_at_price, sku, inventory
 */
class ProductService
{
    public function save(?Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            $isNew = ! $product;
            $product ??= new Product;

            if (array_key_exists('tags', $data)) {
                // Trimmed, de-duplicated (case-insensitive), empty ones dropped
                $data['tags'] = collect($data['tags'] ?? [])->map(fn ($t) => trim((string) $t))->filter()
                    ->unique(fn ($t) => mb_strtolower($t))->values()->all();
            }
            $product->fill(Arr::only($data, ['title', 'description', 'status', 'product_type', 'vendor', 'tags', 'taxable', 'is_gift_card', 'b2b_only', 'digital', 'size_chart']));
            if (array_key_exists('supplier_id', $data)) {
                $product->supplier_id = $data['supplier_id'] ? \App\Models\Supplier::whereKey($data['supplier_id'])->value('id') : null;   // this store's only
            }
            if (array_key_exists('upsell_ids', $data)) {
                $ids = array_values(array_unique(array_map('intval', (array) ($data['upsell_ids'] ?? []))));
                $product->upsell_ids = Product::whereIn('id', $ids)->when($product->id, fn ($q) => $q->whereKeyNot($product->id))->pluck('id')->all() ?: null;   // store-scoped
            }
            if (array_key_exists('preorder', $data)) {
                $product->preorder = \App\Catalog\Preorders::clean($data['preorder']);
            }
            if (array_key_exists('subscription', $data)) {
                $product->subscription = \App\Subscriptions\Plans::clean($data['subscription']);
            }
            if (array_key_exists('seo', $data)) {
                $product->seo = \App\Support\Seo::clean($data['seo']);
            }
            if ($isNew || array_key_exists('handle', $data)) {
                $product->handle = $this->uniqueHandle($data['handle'] ?? $data['title'] ?? $product->title, $product->id);
            }
            $product->status ??= 'draft';
            $product->save();

            if (array_key_exists('images', $data)) {
                $this->syncImages($product, $data['images'] ?? []);
            }

            // Legacy flat fields → single variant
            if (! array_key_exists('variants', $data) && Arr::hasAny($data, ['price', 'inventory', 'sku', 'compare_at_price', 'weight'])) {
                if ($isNew) {
                    $data['options'] = [];
                    $data['variants'] = [Arr::only($data, ['price', 'compare_at_price', 'sku', 'inventory', 'weight'])];
                } else {
                    $this->updateDefaultVariant($product, $data);
                }
            }

            if ($isNew && ! array_key_exists('variants', $data)) {
                $data['options'] = [];
                $data['variants'] = [['price' => 0, 'inventory' => 0]];
            }

            if (array_key_exists('variants', $data)) {
                $options = $this->normalizeOptions($data['options'] ?? $product->options->map->only('name', 'values')->all());
                $this->syncOptions($product, $options);
                $this->syncVariants($product, $options, $data['variants']);
            }

            if (array_key_exists('collection_ids', $data)) {
                $this->syncCollections($product, $data['collection_ids'] ?? []);
            }

            $first = $product->images()->first();
            $product->update(['featured_image' => $first?->url()]);
            app(\App\Catalog\SmartCollections::class)->syncProduct($product->fresh());

            return $product->fresh(['images', 'options', 'variants', 'collections']);
        });
    }

    private function syncImages(Product $product, array $images): void
    {
        $ids = collect($images)->pluck('id')->map(fn ($id) => (int) $id);
        if ($ids->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['images' => __('图片重复')]);
        }

        // Scoped to the current store; must be unattached uploads or already this product's images
        $found = ProductImage::whereIn('id', $ids)
            ->where(fn ($q) => $q->whereNull('product_id')->orWhere('product_id', $product->id))
            ->get()->keyBy('id');

        if ($found->count() !== $ids->count()) {
            throw ValidationException::withMessages(['images' => __('图片不存在或不属于该商品')]);
        }

        // Removed images are deleted (files too — see ProductImage::booted)
        $product->images()->whereNotIn('id', $ids)->get()->each->delete();

        foreach ($images as $position => $img) {
            $found[(int) $img['id']]->update([
                'product_id' => $product->id,
                'position' => $position,
                'alt' => $img['alt'] ?? $found[(int) $img['id']]->alt,
            ]);
        }

        $product->unsetRelation('images');
    }

    /** Add/remove the product from collections; newly added ones put it at the end of the collection. */
    private function syncCollections(Product $product, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $found = \App\Models\Collection::whereIn('id', $ids)->pluck('id')->all();   // store-scoped
        if (count($found) !== count($ids)) {
            throw ValidationException::withMessages(['collection_ids' => __('包含不存在的分类')]);
        }
        // Smart collections manage their own members (from rules)
        $smart = \App\Models\Collection::whereNotNull('rules')->pluck('id')->all();
        $ids = array_values(array_diff($ids, $smart));

        $current = array_values(array_diff($product->collections()->pluck('collections.id')->all(), $smart));
        $product->collections()->detach(array_diff($current, $ids));
        foreach (array_diff($ids, $current) as $cid) {
            $pos = (int) DB::table('collection_product')->where('collection_id', $cid)->max('position') + 1;
            $product->collections()->attach($cid, ['position' => $pos, 'store_id' => $product->store_id]);
        }
    }

    /** @return array<int, array{name:string, values:array<int,string>}> */
    private function normalizeOptions(array $options): array
    {
        $out = [];
        foreach (array_values($options) as $opt) {
            $name = trim((string) ($opt['name'] ?? ''));
            $values = collect($opt['values'] ?? [])->map(fn ($v) => trim((string) $v))->filter()->unique()->values()->all();
            if ($name === '' || ! $values) {
                continue;
            }
            $out[] = ['name' => $name, 'values' => $values];
        }

        if (count($out) > 3) {
            throw ValidationException::withMessages(['options' => __('最多 3 个规格')]);
        }
        if (collect($out)->pluck('name')->map(fn ($n) => Str::lower($n))->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['options' => __('规格名称不能重复')]);
        }

        return $out;
    }

    private function syncOptions(Product $product, array $options): void
    {
        $product->options()->delete();
        foreach ($options as $i => $opt) {
            $product->options()->create([...$opt, 'position' => $i]);
        }
        $product->unsetRelation('options');
    }

    private function syncVariants(Product $product, array $options, array $variants): void
    {
        if (! $variants) {
            throw ValidationException::withMessages(['variants' => __('至少需要一个规格组合')]);
        }
        if (! $options && count($variants) > 1) {
            throw ValidationException::withMessages(['variants' => __('没有设置规格时只能有一个 SKU')]);
        }

        $existing = $product->variants()->get()->keyBy('id');
        $imageIds = $product->images()->pluck('id')->all();
        $seen = [];
        $keep = [];

        foreach (array_values($variants) as $position => $v) {
            $values = $this->variantOptionValues($options, $v['options'] ?? [], $position);
            $key = Str::lower(implode("\u{1F}", $values));
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["variants.$position" => __('规格组合重复：').implode(' / ', $values)]);
            }
            $seen[$key] = true;

            $imageId = $v['image_id'] ?? null;
            if ($imageId && ! in_array((int) $imageId, $imageIds, true)) {
                throw ValidationException::withMessages(["variants.$position.image_id" => __('规格图片必须是该商品的图片')]);
            }

            $attrs = [
                'title' => $values ? implode(' / ', $values) : 'Default',
                'options' => $values ? array_combine(array_column($options, 'name'), $values) : null,
                'price' => Money::toMinor($v['price'] ?? 0),
                'compare_at_price' => isset($v['compare_at_price']) && $v['compare_at_price'] !== '' ? Money::toMinor($v['compare_at_price']) : null,
                'sku' => $v['sku'] ?? null,
                'inventory_quantity' => (int) ($v['inventory'] ?? 0),
                'image_id' => $imageId ?: null,
                'position' => $position,
            ];
            // Weight is optional in the payload (e.g. CSV without a weight column): keep what's there
            if (array_key_exists('weight', $v) && $v['weight'] !== null && $v['weight'] !== '') {
                $attrs['weight'] = max(0, (int) $v['weight']);
            }

            if (! empty($v['id'])) {
                $variant = $existing->get((int) $v['id']);
                if (! $variant) {
                    throw ValidationException::withMessages(["variants.$position.id" => __('规格不存在')]);
                }
                $variant->update($attrs);
            } else {
                $variant = $product->variants()->create($attrs);
            }
            $keep[] = $variant->id;
        }

        // Variants dropped from the payload are deleted (order history keeps its own snapshot)
        ProductVariant::where('product_id', $product->id)->whereNotIn('id', $keep)->delete();
        $product->unsetRelation('variants');
    }

    /** Ordered option values for one variant, validated against the product's options. */
    private function variantOptionValues(array $options, array $given, int $position): array
    {
        $values = [];
        foreach ($options as $opt) {
            $value = $given[$opt['name']] ?? null;
            if (! in_array($value, $opt['values'], true)) {
                throw ValidationException::withMessages([
                    "variants.$position.options" => __('请为第 ').($position + 1).__(' 个 SKU 选择「:p0」', ['p0' => $opt['name']]),
                ]);
            }
            $values[] = $value;
        }

        return $values;
    }

    private function updateDefaultVariant(Product $product, array $data): void
    {
        $attrs = array_filter([
            'price' => isset($data['price']) ? Money::toMinor($data['price']) : null,
            'compare_at_price' => isset($data['compare_at_price']) ? Money::toMinor($data['compare_at_price']) : null,
            'inventory_quantity' => isset($data['inventory']) ? (int) $data['inventory'] : null,
            'sku' => $data['sku'] ?? null,
            'weight' => isset($data['weight']) ? max(0, (int) $data['weight']) : null,
        ], fn ($v) => $v !== null);

        if ($attrs) {
            $product->defaultVariant()->first()?->update($attrs);
        }
    }

    private function uniqueHandle(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'product';
        $handle = $base;
        $i = 1;
        while (Product::where('handle', $handle)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $handle = $base.'-'.(++$i);
        }

        return $handle;
    }
}
