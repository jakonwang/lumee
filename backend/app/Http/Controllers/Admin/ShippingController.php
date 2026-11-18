<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Support\Countries;
use App\Support\Money;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Shipping zones and rates. The whole setup is saved in one go (the editor works on a draft),
 * ids of existing zones/rates are kept so nothing referencing them breaks.
 */
class ShippingController extends Controller
{
    public function show(CurrentStore $current): JsonResponse
    {
        return response()->json($this->payload($current));
    }

    public function update(Request $request, CurrentStore $current): JsonResponse
    {
        $countries = array_keys(Countries::all());
        $data = $request->validate([
            'zones' => ['present', 'array', 'max:50'],
            'zones.*.id' => ['nullable', 'integer'],
            'zones.*.name' => ['required', 'string', 'max:100'],
            'zones.*.countries' => ['present', 'array', 'max:250'],
            'zones.*.countries.*' => ['string', Rule::in($countries)],
            'zones.*.rates' => ['present', 'array', 'max:30'],
            'zones.*.rates.*.id' => ['nullable', 'integer'],
            'zones.*.rates.*.name' => ['required', 'string', 'max:100'],
            'zones.*.rates.*.description' => ['nullable', 'string', 'max:150'],
            'zones.*.rates.*.price' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'zones.*.rates.*.condition' => ['required', Rule::in(['none', 'subtotal', 'weight'])],
            'zones.*.rates.*.min' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'zones.*.rates.*.max' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
        ], [
            'zones.*.name.required' => __('请填写区域名称'),
            'zones.*.rates.*.name.required' => __('请填写运费名称'),
            'zones.*.rates.*.price.required' => __('请填写运费'),
            'zones.*.countries.*.in' => __('国家代码无效'),
        ]);
        // validate() rebuilds arrays rule by rule, which can reorder entries — restore the submitted order
        ksort($data['zones']);
        foreach ($data['zones'] as &$z) {
            ksort($z['rates']);
        }
        unset($z);

        // A country may belong to one zone only; at most one "rest of world" zone
        $seen = [];
        $restOfWorld = 0;
        foreach ($data['zones'] as $i => $zone) {
            if (! $zone['countries']) {
                $restOfWorld++;
            }
            foreach ($zone['countries'] as $code) {
                if (isset($seen[$code])) {
                    throw ValidationException::withMessages(["zones.$i.countries" => (Countries::all()[$code] ?? $code).__('同时出现在「:p0」和「:p1」两个区域', ['p0' => $seen[$code], 'p1' => $zone['name']])]);
                }
                $seen[$code] = $zone['name'];
            }
            foreach ($zone['rates'] as $j => $rate) {
                if ($rate['condition'] !== 'none' && isset($rate['min'], $rate['max']) && (float) $rate['max'] <= (float) $rate['min']) {
                    throw ValidationException::withMessages(["zones.$i.rates.$j.max" => __('「:p0」的上限必须大于下限', ['p0' => $rate['name']])]);
                }
            }
        }
        if ($restOfWorld > 1) {
            throw ValidationException::withMessages(['zones' => __('只能有一个「其他国家」区域（不选国家的区域）')]);
        }

        $store = $current->getOrFail();
        DB::transaction(function () use ($data, $store) {
            $existingZones = ShippingZone::get()->keyBy('id');
            $existingRates = ShippingRate::get()->keyBy('id');
            $keepZones = [];
            $keepRates = [];

            foreach (array_values($data['zones']) as $zi => $z) {
                $attrs = ['name' => trim($z['name']), 'countries' => array_values(array_unique($z['countries'])) ?: null, 'position' => $zi];
                $zone = ! empty($z['id']) && $existingZones->has($z['id'])
                    ? tap($existingZones[$z['id']])->update($attrs)
                    : ShippingZone::create($attrs + ['store_id' => $store->id]);
                $keepZones[] = $zone->id;

                foreach (array_values($z['rates']) as $ri => $r) {
                    $isMoney = $r['condition'] === 'subtotal';
                    $isWeight = $r['condition'] === 'weight';
                    $bound = fn ($v, $on, $money) => $on && $v !== null && $v !== '' ? ($money ? Money::toMinor($v) : (int) round((float) $v)) : null;
                    $rateAttrs = [
                        'zone_id' => $zone->id,
                        'name' => trim($r['name']),
                        'description' => isset($r['description']) && trim($r['description']) !== '' ? trim($r['description']) : null,
                        'price' => Money::toMinor($r['price']),
                        'min_subtotal' => $bound($r['min'] ?? null, $isMoney, true),
                        'max_subtotal' => $bound($r['max'] ?? null, $isMoney, true),
                        'min_weight' => $bound($r['min'] ?? null, $isWeight, false),
                        'max_weight' => $bound($r['max'] ?? null, $isWeight, false),
                        'position' => $ri,
                    ];
                    $rate = ! empty($r['id']) && $existingRates->has($r['id'])
                        ? tap($existingRates[$r['id']])->update($rateAttrs)
                        : ShippingRate::create($rateAttrs + ['store_id' => $store->id]);
                    $keepRates[] = $rate->id;
                }
            }

            ShippingRate::whereNotIn('id', $keepRates)->delete();
            ShippingZone::whereNotIn('id', $keepZones)->delete();
        });

        return response()->json($this->payload($current));
    }

    private function payload(CurrentStore $current): array
    {
        $zones = ShippingZone::with('rates')->orderBy('position')->get();

        return [
            'currency' => $current->getOrFail()->currency,
            'countries' => collect(Countries::all())->map(fn ($name, $code) => ['code' => $code, 'name' => $name])->values(),
            'zones' => $zones->map(fn (ShippingZone $z) => [
                'id' => $z->id,
                'name' => $z->name,
                'countries' => $z->countries ?? [],
                'rates' => $z->rates->map(fn (ShippingRate $r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'description' => $r->description,
                    'price' => Money::format($r->price),
                    'condition' => $r->min_weight !== null || $r->max_weight !== null ? 'weight'
                        : ($r->min_subtotal !== null || $r->max_subtotal !== null ? 'subtotal' : 'none'),
                    'min' => $r->min_weight ?? ($r->min_subtotal !== null ? Money::format($r->min_subtotal) : null),
                    'max' => $r->max_weight ?? ($r->max_subtotal !== null ? Money::format($r->max_subtotal) : null),
                ])->values(),
            ])->values(),
        ];
    }
}
