<?php

namespace App\Collect;

use App\Models\CollectedItem;
use App\Models\Store;
use App\Tenancy\CurrentStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Queue job: fetch the pending items (one URL each), or a whole Shopify store into new items.
 * Each URL is independent: a blocked page fails on its own and the rest carry on.
 */
class RunCollection implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    /** @param int[] $itemIds */
    public function __construct(public int $storeId, public array $itemIds, public string $currency, public ?string $storeUrl = null) {}

    public function handle(CurrentStore $current, Collector $collector, Pipeline $pipeline): void
    {
        $store = Store::find($this->storeId);
        if (! $store) {
            return;
        }
        $current->set($store);   // no request here: set the tenant for scoped models

        if ($this->storeUrl) {
            $marker = CollectedItem::find($this->itemIds[0] ?? 0);   // the placeholder row for the store URL
            try {
                $products = $collector->shopifyStore($this->storeUrl, $this->currency);
                foreach ($products as $p) {
                    rescue(fn () => $pipeline->receive($store, $p['url'], 'shopify', $p['data']));
                }
                $marker?->delete();
            } catch (Throwable $e) {
                $marker && $pipeline->fail($marker, $e->getMessage());
            }

            return;
        }

        foreach (CollectedItem::whereIn('id', $this->itemIds)->where('status', 'pending')->get() as $item) {
            try {
                $r = $collector->product($item->url, $this->currency);
                $item->platform = $r['platform'];
                $item->original = $r['data'];
                $pipeline->process($store, $item, $r['data']);
            } catch (Throwable $e) {
                $pipeline->fail($item, $e->getMessage());
            }
        }
    }
}
