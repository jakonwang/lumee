<?php

namespace App\Services;

use App\Catalog\SmartCollections;
use App\Marketing\StockAlerts;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Storefront\PageCache;

/**
 * Every stock change goes through here (or the ProductVariant "saved" hook for editor/import saves),
 * so each variant has a history: who/what changed it, by how much, and what was left.
 */
class Inventory
{
    /** Reason used by the model hook while a block runs (editor = "edit", CSV = "import", API = "api"). */
    private static string $reason = 'edit';

    public static function as(string $reason, callable $fn): mixed
    {
        $prev = self::$reason;
        self::$reason = $reason;
        try {
            return $fn();
        } finally {
            self::$reason = $prev;
        }
    }

    public static function currentReason(): string
    {
        return self::$reason;
    }

    /** Add (or remove, with a negative delta) stock and log it. Uses an atomic SQL increment. */
    public static function change(int $variantId, int $delta, string $reason, ?int $orderId = null, ?string $note = null): void
    {
        if ($delta === 0) {
            return;
        }
        ProductVariant::withoutGlobalScope('store')->whereKey($variantId)->increment('inventory_quantity', $delta);
        $v = ProductVariant::withoutGlobalScope('store')->find($variantId, ['id', 'store_id', 'product_id', 'inventory_quantity']);
        if ($v) {
            self::log($v, $delta, $reason, $orderId, $note);
            if ($delta > 0) {
                rescue(fn () => app(StockAlerts::class)->restocked($v->id, (int) $v->inventory_quantity - $delta, (int) $v->inventory_quantity));
            }
            $before = (int) $v->inventory_quantity - $delta;
            if (($before > 0) !== ((int) $v->inventory_quantity > 0)) {   // in / out of stock → "stock" rules
                PageCache::bump($v->store_id);   // cached pages show "Sold out" / back in stock right away
                rescue(fn () => app(SmartCollections::class)->syncProduct(Product::withoutGlobalScope('store')->find($v->product_id)));
            }
        }
    }

    public static function log(ProductVariant $v, int $delta, string $reason, ?int $orderId = null, ?string $note = null): void
    {
        $user = request()?->user();
        InventoryAdjustment::withoutGlobalScope('store')->forceCreate([
            'store_id' => $v->store_id,
            'product_id' => $v->product_id,
            'variant_id' => $v->id,
            'delta' => $delta,
            'quantity_after' => (int) $v->inventory_quantity,
            'reason' => $reason,
            'order_id' => $orderId,
            'user_id' => $user instanceof User ? $user->id : null,
            'note' => $note !== null ? mb_substr($note, 0, 255) : null,
            'created_at' => now(),
        ]);
    }
}
