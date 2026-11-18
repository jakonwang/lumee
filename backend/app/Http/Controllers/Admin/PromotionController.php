<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Product;
use App\Models\Promotion;
use App\Support\Money;
use App\Support\Tr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Automatic discounts: timed sales (限时折扣), spend-more tiers (满减), bundles (捆绑优惠). */
class PromotionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Promotion::latest('id')->get()->map(fn (Promotion $p) => $this->present($p)),
            'types' => Tr::map(Promotion::TYPES),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->present(Promotion::findOrFail($id), true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $promotion = new Promotion;
        $this->fill($promotion, $request, true);
        $promotion->save();

        return response()->json(['data' => $this->present($promotion, true)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $promotion = Promotion::findOrFail($id);
        $this->fill($promotion, $request, false);
        $promotion->save();

        return response()->json(['data' => $this->present($promotion, true)]);
    }

    public function destroy(int $id): Response
    {
        Promotion::findOrFail($id)->delete();

        return response()->noContent();
    }

    private function fill(Promotion $p, Request $request, bool $creating): void
    {
        $type = $creating ? $request->input('type') : $p->type;   // type can't change after creation
        $data = $request->validate([
            'type' => [$creating ? 'required' : 'prohibited', Rule::in(array_keys(Promotion::TYPES))],
            'title' => ['required', 'string', 'max:120'],
            'value_type' => ['required', Rule::in(['percentage', 'fixed'])],
            'value' => [$type === 'tiered' ? 'nullable' : 'required', 'numeric', 'gt:0', 'max:1000000'],
            'combines_with_codes' => ['required', 'boolean'],
            'enabled' => ['required', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            // sale
            'applies_to' => [$type === 'sale' ? 'required' : 'nullable', Rule::in(['all', 'products', 'collections'])],
            'product_ids' => ['nullable', 'array', 'max:500'],
            'product_ids.*' => ['integer'],
            'collection_ids' => ['nullable', 'array', 'max:100'],
            'collection_ids.*' => ['integer'],
            'countdown' => ['nullable', 'boolean'],
            // tiered
            'tiers' => [$type === 'tiered' ? 'required' : 'nullable', 'array', 'min:1', 'max:5'],
            'tiers.*.min' => ['required', 'numeric', 'gt:0', 'max:10000000'],
            'tiers.*.value' => ['required', 'numeric', 'gt:0', 'max:1000000'],
        ], [
            'title.required' => __('请填写活动名称'),
            'ends_at.after' => __('结束时间要晚于开始时间'),
            'tiers.required' => __('请至少设置一档满减'),
            'value.required' => __('请填写优惠力度'),
        ]);

        $fail = fn (string $field, string $msg) => throw ValidationException::withMessages([$field => $msg]);
        $percent = $data['value_type'] === 'percentage';
        if ($percent && ($data['value'] ?? 0) >= 100) {
            $fail('value', __('百分比折扣要小于 100%'));
        }

        $config = [];
        switch ($type) {
            case 'sale':
                $config = ['value' => (float) $data['value'], 'applies_to' => $data['applies_to'], 'countdown' => (bool) ($data['countdown'] ?? false)];
                if ($data['applies_to'] === 'products') {
                    $ids = $this->existing(Product::class, $data['product_ids'] ?? []);
                    $ids ?: $fail('product_ids', __('请选择参加活动的商品'));
                    $config['product_ids'] = $ids;
                }
                if ($data['applies_to'] === 'collections') {
                    $ids = $this->existing(Collection::class, $data['collection_ids'] ?? []);
                    $ids ?: $fail('collection_ids', __('请选择参加活动的分类'));
                    $config['collection_ids'] = $ids;
                }
                break;
            case 'tiered':
                $tiers = collect($data['tiers'])->map(fn ($t) => ['min' => Money::toMinor($t['min']), 'value' => (float) $t['value']])->sortBy('min')->values();
                if ($tiers->pluck('min')->unique()->count() !== $tiers->count()) {
                    $fail('tiers', __('每一档的门槛金额不能相同'));
                }
                foreach ($tiers as $i => $t) {
                    if ($percent && $t['value'] >= 100) {
                        $fail("tiers.$i.value", __('百分比折扣要小于 100%'));
                    }
                    if (! $percent && Money::toMinor($t['value']) >= $t['min']) {
                        $fail("tiers.$i.value", __('减免金额要小于门槛金额'));
                    }
                    if ($i > 0 && $t['value'] <= $tiers[$i - 1]['value']) {
                        $fail("tiers.$i.value", __('门槛越高，优惠应该越大'));
                    }
                }
                $config = ['tiers' => $tiers->all()];
                break;
            case 'bundle':
                $ids = $this->existing(Product::class, $data['product_ids'] ?? []);
                if (count($ids) < 2) {
                    $fail('product_ids', __('捆绑优惠至少要选 2 件商品'));
                }
                if (count($ids) > 10) {
                    $fail('product_ids', __('捆绑优惠最多 10 件商品'));
                }
                $config = ['value' => (float) $data['value'], 'product_ids' => $ids];
                break;
        }

        $p->fill([
            'title' => trim($data['title']),
            'value_type' => $data['value_type'],
            'config' => $config,
            'combines_with_codes' => (bool) $data['combines_with_codes'],
            'enabled' => (bool) $data['enabled'],
            'starts_at' => filled($data['starts_at'] ?? null) ? Carbon::parse($data['starts_at']) : null,
            'ends_at' => filled($data['ends_at'] ?? null) ? Carbon::parse($data['ends_at']) : null,
        ]);
        if ($creating) {
            $p->type = $type;
        }
    }

    /** Only ids that exist in this store, in the given order. */
    private function existing(string $model, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $found = $model::whereIn('id', $ids)->pluck('id')->all();   // store-scoped

        return array_values(array_filter($ids, fn ($id) => in_array($id, $found, true)));
    }

    private function present(Promotion $p, bool $detail = false): array
    {
        $c = $p->config ?? [];
        $out = [
            'id' => $p->id,
            'title' => $p->title,
            'type' => $p->type,
            'value_type' => $p->value_type,
            'value' => $c['value'] ?? null,
            'applies_to' => $c['applies_to'] ?? null,
            'countdown' => (bool) ($c['countdown'] ?? false),
            'tiers' => collect($c['tiers'] ?? [])->map(fn ($t) => ['min' => Money::format((int) $t['min']), 'value' => $t['value']])->values(),
            'product_ids' => $c['product_ids'] ?? [],
            'collection_ids' => $c['collection_ids'] ?? [],
            'combines_with_codes' => $p->combines_with_codes,
            'enabled' => $p->enabled,
            'starts_at' => $p->starts_at,
            'ends_at' => $p->ends_at,
            'status' => $p->status(),
            'orders_count' => $p->orders_count,
            'discount_total' => Money::format((int) $p->discount_total),
        ];
        if ($detail) {
            $out['products'] = Product::whereIn('id', $c['product_ids'] ?? [])->get(['id', 'title', 'featured_image', 'status'])
                ->sortBy(fn ($x) => array_search($x->id, $c['product_ids'] ?? [], true))->values();
        }

        return $out;
    }
}
