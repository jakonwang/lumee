<?php

namespace App\Imports;

use App\Models\Collection;
use App\Models\Product;
use App\Models\ProductImport;
use App\Services\ImageFetcher;
use App\Services\ProductService;
use App\Tenancy\CurrentStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Imports the products of a confirmed ProductImport. Each product is independent: one bad
 * row or unreachable image doesn't stop the rest. Progress is saved as it goes.
 */
class RunProductImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;          // re-running could duplicate work; failures are reported instead

    public int $timeout = 1800;

    public function __construct(public int $importId) {}

    public function handle(CurrentStore $current, ProductService $products, ImageFetcher $images): void
    {
        $import = ProductImport::withoutGlobalScope('store')->with('store')->find($this->importId);
        if (! $import || $import->status !== 'queued') {
            return;
        }
        $current->set($import->store);   // no request here: set the tenant for scoped models
        $import->update(['status' => 'running']);

        $results = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'images_failed' => 0, 'errors' => []];
        $note = function (array $p, string $msg) use (&$results) {
            if (count($results['errors']) < 200) {
                $results['errors'][] = ['row' => $p['row'], 'handle' => $p['handle'], 'message' => $msg];
            }
        };

        foreach ($import->products() as $i => $p) {
            try {
                $existing = Product::where('handle', $p['handle'])->with('variants', 'options')->first();

                if ($existing && $import->mode === 'create') {
                    $results['skipped']++;
                    $note($p, __('网址已存在，已跳过（选择「覆盖已有商品」可更新它）'));
                } else {
                    $data = [
                        'title' => $p['title'],
                        'handle' => $p['handle'],
                        'description' => $p['description'],
                        'status' => $p['status'],
                        'vendor' => $p['vendor'],
                        'product_type' => $p['product_type'],
                        'tags' => $p['tags'],
                        'options' => $p['options'],
                        'variants' => $existing ? $this->keepVariantIds($existing, $p['variants']) : $p['variants'],
                        'collection_ids' => $this->collectionIds($p['collections']),
                    ];

                    // Images: download each; a failure is reported but doesn't block the product.
                    // When updating and the file lists no images, existing images are kept.
                    if ($p['images'] || ! $existing) {
                        $ids = [];
                        foreach ($p['images'] as $url) {
                            try {
                                $ids[] = ['id' => $images->fetch($url)->id];
                            } catch (Throwable $e) {
                                $results['images_failed']++;
                                $note($p, __('图片 ').Str::limit($url, 80).__('：').$e->getMessage());
                            }
                        }
                        $data['images'] = $ids;
                    }

                    \App\Services\Inventory::as('import', fn () => $products->save($existing, $data));
                    $existing ? $results['updated']++ : $results['created']++;
                }
            } catch (ValidationException $e) {
                $results['failed']++;
                $note($p, collect($e->errors())->flatten()->first() ?? __('数据无效'));
            } catch (Throwable $e) {
                $results['failed']++;
                $note($p, __('导入失败：').Str::limit($e->getMessage(), 200));
                report($e);
            }

            // Save progress periodically (and at the end) so the admin can show a progress bar
            if ($i % 5 === 4) {
                $import->update(['processed' => $i + 1, 'results' => $results]);
            }
        }

        $import->update(['status' => 'done', 'processed' => $import->total, 'results' => $results, 'payload' => '[]']);
    }

    public function failed(Throwable $e): void
    {
        ProductImport::withoutGlobalScope('store')->whereKey($this->importId)->update([
            'status' => 'failed',
            'results' => json_encode(['errors' => [['row' => 0, 'handle' => '', 'message' => __('导入中断：').Str::limit($e->getMessage(), 200)]]]),
        ]);
    }

    /** Re-use existing variant ids when the option combination is unchanged (keeps carts/orders linked). */
    private function keepVariantIds(Product $existing, array $variants): array
    {
        $byKey = $existing->variants->keyBy(fn ($v) => Str::lower(implode("\u{1F}", array_values($v->options ?? []))));

        return array_map(function ($v) use ($byKey) {
            $match = $byKey->get(Str::lower(implode("\u{1F}", array_values($v['options']))));

            return $match ? [...$v, 'id' => $match->id] : $v;
        }, $variants);
    }

    /** Find collections by title, creating missing ones. */
    private function collectionIds(array $titles): array
    {
        return array_map(function ($title) {
            $title = Str::limit($title, 255, '');
            $c = Collection::where('title', $title)->first();
            if (! $c) {
                $base = Str::slug($title) ?: 'collection';
                $base = $base === 'all' ? 'all-1' : $base;
                $handle = $base;
                for ($n = 2; Collection::where('handle', $handle)->exists(); $n++) {
                    $handle = "{$base}-{$n}";
                }
                $c = Collection::create(['title' => $title, 'handle' => $handle, 'sort_order' => 'manual', 'published' => true]);
            }

            return $c->id;
        }, array_values(array_unique($titles)));
    }
}
