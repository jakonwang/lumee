<?php

namespace App\Collect;

use App\Models\CollectedItem;
use App\Models\Store;
use App\Tenancy\CurrentStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Queue job: publish reviewed items (image downloads make this too slow for a request). */
class PublishCollected implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    /** @param int[] $itemIds */
    public function __construct(public int $storeId, public array $itemIds, public array $options) {}

    public function handle(CurrentStore $current, Publisher $publisher): void
    {
        $store = Store::find($this->storeId);
        if (! $store) {
            return;
        }
        $current->set($store);
        $items = CollectedItem::whereIn('id', $this->itemIds)->where('status', 'ready')->get();
        $result = $publisher->publishMany($items, $this->options);
        // Failures stay in the pool with the reason shown on the item
        foreach ($result['errors'] as $e) {
            CollectedItem::whereKey($e['id'])->update(['error' => mb_substr('上架失败：'.$e['message'], 0, 480)]);
        }
    }
}
