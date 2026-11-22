<?php

namespace App\Collect;

use App\Models\CollectedItem;
use App\Models\Market;
use App\Models\MarketProductExclusion;
use App\Models\Product;
use App\Models\ProductSource;
use App\Models\ProductVariant;
use App\Services\ImageFetcher;
use App\Services\Inventory;
use App\Services\ProductService;
use App\Support\Money;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Review pool → catalog. Creates the product (images downloaded to our storage, never hot-linked),
 * records its cost and source, and limits it to the chosen markets.
 *
 * $options: status (draft|active), collection_ids[], market_ids (null = every market), inventory (default stock)
 */
class Publisher
{
    public function __construct(private ProductService $products, private ImageFetcher $images) {}

    public function publish(CollectedItem $item, array $options): Product
    {
        if ($item->status === 'published' && $item->product_id) {
            throw new RuntimeException('已经上架过了');
        }
        $d = $item->data ?? [];
        if (trim((string) ($d['title'] ?? '')) === '') {
            throw new RuntimeException('标题为空');
        }
        if (collect($d['variants'] ?? [])->contains(fn ($v) => ($v['price'] ?? 0) <= 0)) {
            throw new RuntimeException('有规格没有售价');
        }

        // Images first: variants point at them, and a product with no image is not worth publishing
        $imageIds = [];
        $failed = 0;
        foreach (array_slice($d['images'] ?? [], 0, Item::MAX_IMAGES) as $url) {
            try {
                $imageIds[$url] = $this->images->fetch($url)->id;
            } catch (Throwable) {
                $failed++;
            }
        }
        if (! $imageIds && ($d['images'] ?? [])) {
            throw new RuntimeException('图片全部下载失败');
        }

        $stock = max(0, (int) ($options['inventory'] ?? 0));
        $sku = fn (int $i) => 'C'.$item->id.'-'.($i + 1);
        $product = Inventory::as('import', fn () => $this->products->save(null, [
            'title' => $d['title'],
            'description' => $d['description'] ?? '',
            'status' => in_array($options['status'] ?? 'draft', ['draft', 'active'], true) ? $options['status'] : 'draft',
            'product_type' => $d['product_type'] ?? null,
            'tags' => $d['tags'] ?? [],
            'images' => array_map(fn ($id) => ['id' => $id], array_values($imageIds)),
            'options' => $d['options'] ?? [],
            'variants' => array_map(fn ($v, $i) => [
                'options' => $v['options'],
                'price' => Money::format((int) $v['price']),
                'compare_at_price' => $v['compare_at_price'] ? Money::format((int) $v['compare_at_price']) : null,
                'sku' => $v['sku'] ?: $sku($i),
                'inventory' => $v['inventory'] ?? $stock,
                'weight' => $v['weight'],
                'image_id' => $v['image'] ? ($imageIds[$v['image']] ?? null) : null,
            ], $d['variants'], array_keys($d['variants'])),
            'collection_ids' => array_map('intval', (array) ($options['collection_ids'] ?? [])),
        ]));

        // Cost per variant (store currency) for margins; matched by option values
        $rate = (float) ($d['pricing']['rate'] ?? 1);
        foreach ($product->variants()->get() as $variant) {
            $src = collect($d['variants'])->first(fn ($v) => $v['options'] == ($variant->options ?? []));
            if ($src) {
                ProductVariant::whereKey($variant->id)->update(['cost' => (int) round($src['cost'] * $rate)]);
            }
        }

        ProductSource::create(['product_id' => $product->id, 'url' => $item->url, 'url_hash' => $item->url_hash, 'platform' => $item->platform, 'currency' => $d['currency'] ?? null]);

        // Markets: sold only where chosen
        if (is_array($options['market_ids'] ?? null)) {
            foreach (Market::whereNotIn('id', $options['market_ids'])->pluck('id') as $marketId) {
                MarketProductExclusion::firstOrCreate(['market_id' => $marketId, 'product_id' => $product->id]);
            }
        }

        // Content score: below the brand kit's minimum, an "active" product is kept as a draft
        $store = app(\App\Tenancy\CurrentStore::class)->getOrFail();
        $score = app(\App\Content\ContentScorer::class)->rescore($store, $product->fresh(['images', 'variants']));
        $min = \App\Content\BrandKit::for($store)->all()['min_score'];
        $notes = $failed ? ["{$failed} 张图片下载失败"] : [];
        if ($product->status === 'active' && $min > 0 && $score < $min) {
            Product::whereKey($product->id)->update(['status' => 'draft']);
            $notes[] = "内容分 {$score} 低于上架标准 {$min}，已存为草稿";
        }

        $item->forceFill(['status' => 'published', 'product_id' => $product->id, 'error' => $notes ? implode('；', $notes) : null])->save();

        return $product;
    }

    /** Publish many; returns counts and per-item errors (one failure never stops the rest). */
    public function publishMany(iterable $items, array $options): array
    {
        $out = ['published' => 0, 'failed' => 0, 'errors' => []];
        foreach ($items as $item) {
            try {
                $this->publish($item, $options);
                $out['published']++;
            } catch (ValidationException $e) {
                $out['failed']++;
                $out['errors'][] = ['id' => $item->id, 'message' => collect($e->errors())->flatten()->first()];
            } catch (Throwable $e) {
                $out['failed']++;
                $out['errors'][] = ['id' => $item->id, 'message' => Str::limit($e->getMessage(), 200)];
                $e instanceof RuntimeException || report($e);
            }
        }

        return $out;
    }
}
