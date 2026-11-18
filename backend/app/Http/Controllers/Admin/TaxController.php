<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TaxRate;
use App\Services\TaxService;
use App\Support\Countries;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Tax settings: inclusive/exclusive prices, tax on shipping, rates by country / region. */
class TaxController extends Controller
{
    public function show(CurrentStore $current, TaxService $tax): JsonResponse
    {
        return response()->json($this->payload($current, $tax));
    }

    public function update(Request $request, CurrentStore $current, TaxService $tax): JsonResponse
    {
        $data = $request->validate([
            'included' => ['required', 'boolean'],
            'shipping' => ['required', 'boolean'],
            'stripe_tax' => ['sometimes', 'boolean'],
            'rates' => ['present', 'array', 'max:500'],
            'rates.*.country' => ['required', Rule::in(array_keys(Countries::all()))],
            'rates.*.region' => ['nullable', 'string', 'max:50'],
            'rates.*.name' => ['required', 'string', 'max:50'],
            'rates.*.rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ], ['rates.*.name.required' => __('请填写税名（如 VAT、Sales tax）')]);

        // One rate per country + region
        $seen = [];
        foreach ($data['rates'] as $i => $r) {
            $key = $r['country'].'|'.strtoupper(trim((string) ($r['region'] ?? '')));
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["rates.$i.country" => __('同一个国家/地区重复设置了税率')]);
            }
            $seen[$key] = true;
        }

        $store = $current->getOrFail();
        DB::transaction(function () use ($data, $store) {
            TaxRate::query()->delete();   // store-scoped: replace the whole table
            foreach ($data['rates'] as $r) {
                $region = strtoupper(trim((string) ($r['region'] ?? '')));
                TaxRate::create([
                    'store_id' => $store->id, 'country' => $r['country'], 'region' => $region === '' ? null : $region,
                    'name' => trim($r['name']), 'rate' => round((float) $r['rate'], 3),
                ]);
            }
            $settings = $store->settings ?? [];
            $settings['taxes'] = ['included' => (bool) $data['included'], 'shipping' => (bool) $data['shipping'],
                'stripe_tax' => (bool) ($data['stripe_tax'] ?? false)];
            $store->settings = $settings;
            $store->save();
        });

        return response()->json($this->payload($current, app(TaxService::class)));
    }

    private function payload(CurrentStore $current, TaxService $tax): array
    {
        $names = Countries::all();

        return [
            'settings' => $tax->settings($current->getOrFail()),
            'stripe_tax_available' => (string) config('services.stripe.secret') !== '',
            'rates' => TaxRate::orderBy('country')->orderBy('region')->get()->map(fn (TaxRate $r) => [
                'country' => $r->country, 'region' => $r->region, 'name' => $r->name, 'rate' => $r->rate,
            ]),
            'presets' => collect(TaxService::PRESETS)->map(fn ($p, $key) => [
                'key' => $key,
                'name' => $p['name'],
                'rates' => collect($p['rates'])->map(fn ($rate, $c) => ['country' => $c, 'rate' => $rate])->values(),
                'countries' => count($p['rates']),
            ])->values(),
            'countries' => collect($names)->map(fn ($n, $c) => ['code' => $c, 'name' => $n])->values(),
        ];
    }
}
