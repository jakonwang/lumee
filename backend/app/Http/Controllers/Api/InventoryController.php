<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Webhooks\Webhooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Open API: set or adjust a variant's stock (e.g. from a warehouse / ERP). */
class InventoryController extends Controller
{
    public function adjust(Request $request, Webhooks $webhooks): JsonResponse
    {
        $data = $request->validate([
            'variant_id' => ['nullable', 'integer', 'required_without:sku'],
            'sku' => ['nullable', 'string', 'max:100', 'required_without:variant_id'],
            'available' => ['nullable', 'integer', 'min:-100000', 'max:1000000', 'required_without:adjustment'],
            'adjustment' => ['nullable', 'integer', 'min:-1000000', 'max:1000000', 'required_without:available'],
        ]);

        $variant = DB::transaction(function () use ($data) {
            $q = ProductVariant::query()->lockForUpdate();   // store-scoped
            $variant = isset($data['variant_id']) ? $q->findOrFail($data['variant_id']) : $q->where('sku', $data['sku'])->firstOrFail();
            $variant->inventory_quantity = array_key_exists('available', $data) && $data['available'] !== null
                ? (int) $data['available']
                : (int) $variant->inventory_quantity + (int) $data['adjustment'];
            \App\Services\Inventory::as('api', fn () => $variant->save());

            return $variant;
        });
        $webhooks->productChanged($variant->product_id, 'products/update');

        return response()->json(['data' => [
            'variant_id' => $variant->id, 'product_id' => $variant->product_id, 'sku' => $variant->sku, 'available' => (int) $variant->inventory_quantity,
        ]]);
    }

    public function customer(int $id): JsonResponse
    {
        return response()->json(['data' => \App\Webhooks\Webhooks::customer(\App\Models\Customer::findOrFail($id))]);
    }

    /** GET /api/v1 — who am I + what this token can do. */
    public function me(Request $request): JsonResponse
    {
        $token = $request->attributes->get('api_token');
        $store = app(\App\Tenancy\CurrentStore::class)->getOrFail();

        return response()->json(['data' => [
            'store' => ['id' => $store->id, 'name' => $store->name, 'currency' => $store->currency, 'url' => $store->storefrontUrl()],
            'token' => ['name' => $token->name, 'scopes' => $token->scopes],
            'api_version' => 'v1',
        ]]);
    }
}
