<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\ProductVariant;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Products › Wholesale price lists: which customer tag, % off, fixed variant prices, minimum order. */
class PriceListController extends Controller
{
    public function index(): JsonResponse
    {
        $lists = PriceList::withCount('prices')->latest('id')->get();
        $tagged = fn (string $tag) => Customer::whereNotNull('tags')->get(['tags'])
            ->filter(fn ($c) => in_array(mb_strtolower($tag), array_map('mb_strtolower', $c->tags ?? []), true))->count();

        return response()->json(['data' => $lists->map(fn (PriceList $l) => [...$this->row($l), 'customers' => $tagged($l->customer_tag)])]);
    }

    public function show(int $id): JsonResponse
    {
        $l = PriceList::with('prices')->findOrFail($id);
        $variants = ProductVariant::with('product:id,title')->whereIn('id', $l->prices->pluck('variant_id'))->get()->keyBy('id');

        return response()->json(['data' => [...$this->row($l), 'prices' => $l->prices->filter(fn ($p) => $variants->has($p->variant_id))->map(function ($p) use ($variants) {
            $v = $variants[$p->variant_id];

            return ['variant_id' => $v->id, 'title' => $v->product?->title, 'variant_title' => $v->title === 'Default' ? null : $v->title,
                'sku' => $v->sku, 'regular' => Money::format((int) $v->getRawOriginal('price')), 'price' => $p->price !== null ? Money::format($p->price) : null,
                'min_qty' => $p->min_qty, 'step' => $p->step,
                'tiers' => collect($p->tiers ?? [])->map(fn ($t) => ['min' => $t['min'], 'price' => Money::format((int) $t['price'])])->all()];
        })->values()]]);
    }

    public function store(Request $request): JsonResponse
    {
        $l = DB::transaction(fn () => $this->save(new PriceList, $request));

        return response()->json(['data' => $this->row($l)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $l = DB::transaction(fn () => $this->save(PriceList::findOrFail($id), $request));

        return $this->show($l->id);
    }

    public function destroy(int $id): JsonResponse
    {
        PriceList::findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    private function save(PriceList $l, Request $request): PriceList
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'customer_tag' => ['required', 'string', 'max:40'],
            'percent_off' => ['required', 'numeric', 'min:0', 'max:90'],
            'min_subtotal' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'enabled' => ['boolean'],
            'prices' => ['array', 'max:2000'],
            'prices.*.variant_id' => ['required', 'integer'],
            'prices.*.price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'prices.*.min_qty' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'prices.*.step' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'prices.*.tiers' => ['nullable', 'array', 'max:10'],
            'prices.*.tiers.*.min' => ['required', 'integer', 'min:2', 'max:1000000'],
            'prices.*.tiers.*.price' => ['required', 'numeric', 'min:0', 'max:1000000'],
        ]);
        $l->fill([
            'name' => trim($data['name']),
            'customer_tag' => CustomerController::cleanTags([$data['customer_tag']])[0] ?? throw ValidationException::withMessages(['customer_tag' => __('请填写客户标签')]),
            'percent_off' => $data['percent_off'],
            'min_subtotal' => Money::toMinor($data['min_subtotal'] ?? 0),
            'enabled' => $data['enabled'] ?? true,
        ])->save();

        if (array_key_exists('prices', $data)) {
            $ids = array_column($data['prices'], 'variant_id');
            $valid = ProductVariant::whereIn('id', $ids)->pluck('id')->flip();   // this store's variants only
            $l->prices()->delete();
            foreach ($data['prices'] as $p) {
                if ($valid->has($p['variant_id'])) {
                    $tiers = collect($p['tiers'] ?? [])->map(fn ($t) => ['min' => (int) $t['min'], 'price' => Money::toMinor($t['price'])])
                        ->unique('min')->sortBy('min')->values()->all();
                    $l->prices()->updateOrCreate(['variant_id' => $p['variant_id']], [
                        'price' => isset($p['price']) && $p['price'] !== '' ? Money::toMinor($p['price']) : null,
                        'min_qty' => (int) ($p['min_qty'] ?? 1), 'step' => (int) ($p['step'] ?? 1), 'tiers' => $tiers ?: null,
                    ]);
                }
            }
        }

        return $l;
    }

    private function row(PriceList $l): array
    {
        return [
            'id' => $l->id, 'name' => $l->name, 'customer_tag' => $l->customer_tag, 'percent_off' => $l->percent_off,
            'min_subtotal' => Money::format($l->min_subtotal), 'enabled' => $l->enabled,
            'fixed_prices' => $l->prices_count ?? $l->prices()->count(), 'updated_at' => $l->updated_at,
        ];
    }
}
