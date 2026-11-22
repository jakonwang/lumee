<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Support\Money;
use App\Supply\Purchasing;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Products › Purchasing (采购): suppliers, restock suggestions, purchase orders. */
class PurchasingController extends Controller
{
    public function index(Request $request, CurrentStore $current, Purchasing $purchasing): JsonResponse
    {
        $store = $current->getOrFail();
        $status = $request->query('status');

        return response()->json([
            'suppliers' => Supplier::orderBy('name')->get()->map(fn ($s) => $this->supplierRow($store, $s)),
            'orders' => PurchaseOrder::with('supplier:id,name')->when(in_array($status, PurchaseOrder::STATUSES, true), fn ($q) => $q->where('status', $status))
                ->latest('id')->limit(100)->get()->map(fn ($po) => $this->orderRow($po)),
            'suggestions' => $purchasing->suggestions($store)->map(fn ($r) => [...$r, 'cost' => $r['cost'] !== null ? Money::format($r['cost']) : null])->values(),
            'currency' => $store->baseCurrency(),
        ]);
    }

    public function saveSupplier(Request $request, ?Supplier $supplier = null): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'contact_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'platform' => ['nullable', Rule::in(['1688', 'alibaba', 'factory', 'other'])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $supplier ??= new Supplier(['token' => Purchasing::newToken()]);
        $supplier->fill($data)->save();

        return response()->json(['data' => $this->supplierRow(app(CurrentStore::class)->getOrFail(), $supplier)], $supplier->wasRecentlyCreated ? 201 : 200);
    }

    public function updateSupplier(Request $request, Supplier $supplier): JsonResponse
    {
        return $this->saveSupplier($request, $supplier);
    }

    /** A new portal link (the old one stops working). */
    public function resetSupplierLink(Supplier $supplier, CurrentStore $current): JsonResponse
    {
        $supplier->forceFill(['token' => Purchasing::newToken()])->save();

        return response()->json(['data' => $this->supplierRow($current->getOrFail(), $supplier)]);
    }

    public function destroySupplier(Supplier $supplier): JsonResponse
    {
        $supplier->delete();

        return response()->json(['ok' => true]);
    }

    /** Assign products to a supplier (restock suggestions are grouped by it). */
    public function assignProducts(Request $request, Supplier $supplier): JsonResponse
    {
        $data = $request->validate(['product_ids' => ['required', 'array', 'max:500'], 'product_ids.*' => ['integer']]);

        return response()->json(['done' => Product::whereIn('id', $data['product_ids'])->update(['supplier_id' => $supplier->id])]);
    }

    /** lines: [{variant_id, quantity, cost (decimal, store currency)}] */
    public function createOrder(Request $request, Purchasing $purchasing): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:300'],
            'lines.*.variant_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'lines.*.cost' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ]);
        $supplier = Supplier::findOrFail($data['supplier_id']);
        $po = $purchasing->create($supplier, array_map(fn ($l) => [...$l, 'cost' => isset($l['cost']) && $l['cost'] !== '' ? Money::toMinor($l['cost']) : null], $data['lines']), $data['note'] ?? null);

        return response()->json(['data' => $this->orderRow($po->load('supplier:id,name'))], 201);
    }

    /** send | receive | cancel */
    public function orderAction(Request $request, PurchaseOrder $order, Purchasing $purchasing, CurrentStore $current): JsonResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['send', 'receive', 'cancel'])]]);
        match ($data['action']) {
            'send' => $purchasing->send($current->getOrFail(), $order),
            'receive' => $purchasing->receive($order),
            'cancel' => $purchasing->cancel($order),
        };

        return response()->json(['data' => $this->orderRow($order->fresh('supplier:id,name'))]);
    }

    private function supplierRow($store, Supplier $s): array
    {
        return [...$s->only('id', 'name', 'contact_name', 'email', 'phone', 'platform', 'note'),
            'portal_url' => Purchasing::portalUrl($store, $s), 'products' => Product::where('supplier_id', $s->id)->count(),
            'open_orders' => PurchaseOrder::where('supplier_id', $s->id)->whereIn('status', ['sent', 'confirmed', 'shipped'])->count()];
    }

    private function orderRow(PurchaseOrder $po): array
    {
        return [
            ...$po->only('id', 'number', 'status', 'note', 'supplier_note', 'tracking', 'sent_at', 'confirmed_at', 'shipped_at', 'received_at', 'created_at'),
            'name' => $po->name(), 'supplier' => $po->supplier?->name, 'supplier_id' => $po->supplier_id,
            'expected_at' => $po->expected_at?->toDateString(), 'total' => Money::format($po->total),
            'items' => array_map(fn ($i) => [...$i, 'cost' => Money::format((int) $i['cost'])], $po->items),
            'units' => array_sum(array_column($po->items, 'quantity')),
        ];
    }
}
