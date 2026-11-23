<?php

namespace App\Collect;

use App\Markets\MarketFit;
use App\Models\CollectedItem;
use App\Models\Store;
use Illuminate\Support\Str;
use Throwable;

/**
 * Collected data → review pool: rule cleanup, pricing, duplicate check. Every step's notes land
 * in collected_items.checks so the merchant sees what to look at before publishing.
 */
class Pipeline
{
    public function __construct(private Cleaner $cleaner, private Pricing $pricing, private Duplicates $duplicates) {}

    /** Create (or refresh) the pool entry for a URL. Published items are left alone. */
    public function receive(Store $store, string $url, string $platform, array $data): CollectedItem
    {
        $item = CollectedItem::firstOrNew(['url_hash' => Item::hash($url)]);
        if ($item->exists && $item->status === 'published') {
            return $item;
        }
        $item->fill(['url' => $url, 'platform' => $platform, 'original' => $data, 'error' => null]);

        return $this->process($store, $item, $data);
    }

    public function process(Store $store, CollectedItem $item, array $data): CollectedItem
    {
        [$data, $notes] = $this->cleaner->clean($store, $data);
        try {
            $data = $this->pricing->apply($store, $data);
        } catch (Throwable $e) {
            $notes[] = ['type' => 'pricing', 'message' => $e->getMessage()];
        }
        $item->fill([
            'status' => 'ready',
            'data' => $data,
            'checks' => ['notes' => $notes, 'duplicate' => $this->duplicates->find($item->url, $data['title'] ?? '')],
        ])->save();

        return $this->fit($store, $item, false);
    }

    public function fail(CollectedItem $item, string $reason): void
    {
        $item->forceFill(['status' => 'failed', 'error' => Str::limit($reason, 480)])->save();
    }

    /** AI rewrite on top of the cleaned data (keeps pricing and the rule notes). */
    public function rewrite(Store $store, CollectedItem $item): CollectedItem
    {
        $d = app(CollectAi::class)->rewrite($store, $item->data);
        $risks = $d['ai_risks'] ?? [];
        unset($d['ai_risks']);
        [$d, $notes] = $this->cleaner->clean($store, $d);
        $d['variants'] = array_map(fn ($v, $old) => [...$v, 'price' => $old['price'] ?? $v['price'], 'compare_at_price' => $old['compare_at_price'] ?? $v['compare_at_price']],
            $d['variants'], array_pad($item->data['variants'] ?? [], count($d['variants']), []));
        $d['pricing'] = $item->data['pricing'] ?? null;
        foreach ($risks as $r) {
            $notes[] = ['type' => 'ai', 'message' => $r];
        }
        $item->forceFill(['data' => Item::normalize($d), 'checks' => [...($item->checks ?? []), 'notes' => $notes, 'ai' => now()->toIso8601String()]])->save();

        return $this->fit($store, $item, true);
    }

    /**
     * Which markets it suits (rules and local trends; with $withAi also Claude's judgement per market).
     * A failure never loses the collected data: it becomes a note.
     */
    public function fit(Store $store, CollectedItem $item, bool $withAi): CollectedItem
    {
        try {
            return app(MarketFit::class)->scoreItem($store, $item, $withAi && MarketFit::aiReady($store));
        } catch (Throwable $e) {
            $withAi || report($e);
            $checks = $item->checks ?? [];
            $checks['notes'][] = ['type' => 'markets', 'message' => '市场适合度：'.Str::limit($e->getMessage(), 200)];
            $item->forceFill(['checks' => $checks])->save();

            return $item;
        }
    }

    public function reprice(Store $store, CollectedItem $item, ?\App\Models\PricingRule $rule = null): CollectedItem
    {
        $item->forceFill(['data' => $this->pricing->apply($store, $item->data, $rule)])->save();

        return $item;
    }
}
