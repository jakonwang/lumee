<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InventoryAdjustment;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Inventory;
use App\Webhooks\Webhooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Product page › stock: change history per variant, and manual adjustments with a reason. */
class InventoryController extends Controller
{
    public function history(Request $request, Product $product): JsonResponse
    {
        $rows = InventoryAdjustment::with('user:id,name')->where('product_id', $product->id)
            ->when($request->query('variant_id'), fn ($q, $v) => $q->where('variant_id', (int) $v))
            ->latest('id')->limit(100)->get();
        $orders = Order::whereIn('id', $rows->pluck('order_id')->filter()->unique())->get(['id', 'number'])->keyBy('id');
        $variants = ProductVariant::where('product_id', $product->id)->pluck('title', 'id');

        return response()->json(['data' => $rows->map(fn ($r) => [
            'id' => $r->id,
            'variant_id' => $r->variant_id,
            'variant' => ($variants[$r->variant_id] ?? null) === 'Default' ? null : ($variants[$r->variant_id] ?? null),
            'delta' => $r->delta,
            'quantity_after' => $r->quantity_after,
            'reason' => $r->reason,
            'order' => $r->order_id && isset($orders[$r->order_id]) ? ['id' => $r->order_id, 'name' => $orders[$r->order_id]->name()] : null,
            'user' => $r->user?->name,
            'note' => $r->note,
            'created_at' => $r->created_at,
        ])]);
    }

    /** Set the count ("available") or add/remove ("adjustment"), e.g. after a stocktake or damaged goods. */
    public function adjust(Request $request, Product $product, Webhooks $webhooks): JsonResponse
    {
        $data = $request->validate([
            'variant_id' => ['required', 'integer'],
            'available' => ['nullable', 'integer', 'min:-100000', 'max:1000000', 'required_without:adjustment'],
            'adjustment' => ['nullable', 'integer', 'min:-1000000', 'max:1000000', 'required_without:available'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $variant = DB::transaction(function () use ($data, $product) {
            $v = ProductVariant::where('product_id', $product->id)->lockForUpdate()->findOrFail($data['variant_id']);
            $delta = isset($data['available']) ? (int) $data['available'] - (int) $v->inventory_quantity : (int) $data['adjustment'];
            Inventory::change($v->id, $delta, 'manual', null, $data['note'] ?? null);

            return $v->fresh();
        });
        $webhooks->productChanged($product->id, 'products/update');

        return response()->json(['data' => ['variant_id' => $variant->id, 'inventory' => (int) $variant->inventory_quantity]]);
    }
}
