<?php

namespace App\Http\Controllers\Admin;

use App\Catalog\DigitalDelivery;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductFile;
use App\Shipping\Pickup;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Digital product files, resetting a buyer's download links, and local pickup locations. */
class ProductFileController extends Controller
{
    public const MAX_FILES = 10;

    public function store(Request $request, Product $product): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:512000']], ['file.max' => __('文件不能超过 500MB')]);
        if (ProductFile::where('product_id', $product->id)->count() >= self::MAX_FILES) {
            throw ValidationException::withMessages(['file' => __('每个商品最多 :p0 个文件', ['p0' => self::MAX_FILES])]);
        }
        $upload = $request->file('file');
        $name = Str::limit(preg_replace('/[^\pL\pN._ -]+/u', '_', $upload->getClientOriginalName()) ?: 'file', 190, '');
        $path = $upload->storeAs("digital/{$product->store_id}/{$product->id}", Str::uuid().'-'.$name, 'local');
        $file = ProductFile::create(['store_id' => $product->store_id, 'product_id' => $product->id, 'path' => $path, 'filename' => $name, 'size' => $upload->getSize()]);
        if (! $product->digital) {
            $product->forceFill(['digital' => true])->save();
        }

        return response()->json(['data' => $file->toApi()], 201);
    }

    public function destroy(Product $product, int $id): JsonResponse
    {
        ProductFile::where('product_id', $product->id)->findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    public function resetDownloads(Order $order, DigitalDelivery $digital): OrderResource
    {
        $digital->reset($order);

        return new OrderResource($order->fresh()->load('items'));
    }

    public function pickup(CurrentStore $current): JsonResponse
    {
        return response()->json(['data' => Pickup::settings($current->getOrFail())]);
    }

    public function savePickup(Request $request, CurrentStore $current): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'locations' => ['present', 'array', 'max:'.Pickup::MAX_LOCATIONS],
            'locations.*.name' => ['required', 'string', 'max:80'],
            'locations.*.address' => ['required', 'string', 'max:200'],
            'locations.*.hours' => ['nullable', 'string', 'max:120'],
            'locations.*.instructions' => ['nullable', 'string', 'max:300'],
        ], ['locations.*.name.required' => __('请填写自提点名称'), 'locations.*.address.required' => __('请填写自提点地址')]);
        if ($data['enabled'] && ! $data['locations']) {
            throw ValidationException::withMessages(['locations' => __('开启到店自提前，请至少添加一个自提点')]);
        }
        $store = $current->getOrFail();
        $settings = $store->settings ?? [];
        $settings['pickup'] = ['enabled' => (bool) $data['enabled'], 'locations' => array_values(array_map(fn ($l) => [
            'name' => trim($l['name']), 'address' => trim($l['address']), 'hours' => trim((string) ($l['hours'] ?? '')) ?: null,
            'instructions' => trim((string) ($l['instructions'] ?? '')) ?: null,
        ], $data['locations']))];
        $store->settings = $settings;
        $store->save();

        return response()->json(['data' => Pickup::settings($store)]);
    }
}
