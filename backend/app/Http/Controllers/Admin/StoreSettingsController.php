<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\StoreResource;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StoreSettingsController extends Controller
{
    public function show(CurrentStore $current): JsonResponse
    {
        return response()->json($this->payload($current));
    }

    public function update(Request $request, CurrentStore $current): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'currency' => ['required', Rule::in(config('lumee.currencies'))],
            'timezone' => ['required', 'timezone:all'],
            'seo' => ['sometimes', 'nullable', 'array'],
            'seo.title' => ['nullable', 'string', 'max:70'],
            'seo.description' => ['nullable', 'string', 'max:320'],
        ], ['name.required' => __('请填写店铺名称')]);

        $store = $current->getOrFail();
        $store->fill(\Illuminate\Support\Arr::only($data, ['name', 'currency', 'timezone']));
        if (array_key_exists('seo', $data)) {
            $settings = $store->settings ?? [];
            $settings['seo'] = \App\Support\Seo::clean($data['seo']);
            $store->settings = $settings;
        }
        $store->save();

        return response()->json($this->payload($current));
    }

    private function payload(CurrentStore $current): array
    {
        $store = $current->getOrFail()->load('domains');

        return [
            'store' => [
                ...(new StoreResource($store))->resolve(),
                'timezone' => $store->timezone,
                'seo' => ['title' => $store->settings['seo']['title'] ?? null, 'description' => $store->settings['seo']['description'] ?? null],
            ],
            'currencies' => config('lumee.currencies'),
            'has_orders' => $store->id && \App\Models\Order::exists(),
        ];
    }
}
