<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Product;
use App\Models\StoreMedia;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CollectionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $collections = Collection::query()
            ->with('image')
            ->withCount('products')
            ->when($request->query('q'), fn ($q, $t) => $q->where('title', 'like', "%{$t}%"))
            ->orderBy('title')
            ->get();

        return response()->json(['data' => $collections->map(fn (Collection $c) => [
            'id' => $c->id,
            'title' => $c->title,
            'handle' => $c->handle,
            'published' => $c->published,
            'products_count' => $c->products_count,
            'smart' => $c->isSmart(),
            'image_url' => $c->imageUrl(),
        ])]);
    }

    public function show(Collection $collection): JsonResponse
    {
        return response()->json(['data' => $this->detail($collection)]);
    }

    public function store(Request $request): JsonResponse
    {
        $collection = DB::transaction(fn () => $this->save(new Collection, $this->validated($request, true)));

        return response()->json(['data' => $this->detail($collection)], 201);
    }

    public function update(Request $request, Collection $collection): JsonResponse
    {
        DB::transaction(fn () => $this->save($collection, $this->validated($request, false)));

        return response()->json(['data' => $this->detail($collection->fresh())]);
    }

    public function destroy(Collection $collection)
    {
        $collection->delete();   // products are untouched; menu links to it disappear from the storefront

        return response()->noContent();
    }

    private function save(Collection $c, array $data): Collection
    {
        $c->fill(collect($data)->only('title', 'description', 'image_id', 'sort_order', 'published')->all());
        if (array_key_exists('seo', $data)) {
            $c->seo = \App\Support\Seo::clean($data['seo']);
        }
        if (! $c->exists || array_key_exists('handle', $data)) {
            $c->handle = $this->uniqueHandle($data['handle'] ?? $data['title'] ?? $c->title, $c->id);
        }
        $c->sort_order ??= 'manual';
        if (array_key_exists('rules', $data)) {
            $rules = array_values(array_map(fn ($r) => [
                'field' => $r['field'], 'op' => $r['op'], 'value' => $r['field'] === 'stock' ? null : ($r['field'] === 'price' ? (float) $r['value'] : trim((string) $r['value'])),
            ], $data['rules'] ?? []));
            $c->rules = $rules ?: null;
            $c->rules_match = ($data['rules_match'] ?? 'all') === 'any' ? 'any' : 'all';
        }
        $c->save();

        if ($c->isSmart()) {
            app(\App\Catalog\SmartCollections::class)->sync($c);   // members come from the rules

            return $c;
        }
        if (array_key_exists('product_ids', $data)) {
            $ids = array_values(array_unique(array_map('intval', $data['product_ids'])));
            // Store-scoped: another store's product ids are simply "not found"
            if (Product::whereIn('id', $ids)->count() !== count($ids)) {
                throw ValidationException::withMessages(['product_ids' => __('包含不存在的商品')]);
            }
            $c->products()->sync(collect($ids)->mapWithKeys(fn ($id, $i) => [
                $id => ['position' => $i, 'store_id' => $c->store_id],
            ])->all());
        }

        return $c;
    }

    private function detail(Collection $c): array
    {
        $c->load('image');
        $products = $c->products()->with('variants:id,product_id,price,inventory_quantity')
            ->orderByPivot('position')->get();

        return [
            'id' => $c->id,
            'title' => $c->title,
            'handle' => $c->handle,
            'description' => $c->description,
            'image_id' => $c->image_id,
            'image_url' => $c->image?->url(),
            'sort_order' => $c->sort_order,
            'published' => $c->published,
            'rules' => $c->rules ?? [],
            'rules_match' => $c->rules_match ?? 'all',
            'smart' => $c->isSmart(),
            'seo' => ['title' => $c->seo['title'] ?? null, 'description' => $c->seo['description'] ?? null],
            'sorts' => \App\Support\Tr::map(Collection::SORTS),
            'products' => $products->map(fn (Product $p) => [
                'id' => $p->id,
                'title' => $p->title,
                'status' => $p->status,
                'featured_image' => $p->featured_image,
                'price' => Money::format($p->variants->min('price')),
                'inventory' => (int) $p->variants->sum('inventory_quantity'),
            ]),
        ];
    }

    private function validated(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'handle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'image_id' => ['sometimes', 'nullable', 'integer'],
            'sort_order' => ['sometimes', Rule::in(array_keys(Collection::SORTS))],
            'published' => ['sometimes', 'boolean'],
            'seo' => ['sometimes', 'nullable', 'array'],
            'seo.title' => ['nullable', 'string', 'max:70'],
            'seo.description' => ['nullable', 'string', 'max:320'],
            'product_ids' => ['sometimes', 'array', 'max:1000'],
            'product_ids.*' => ['integer'],
            'rules' => ['sometimes', 'nullable', 'array', 'max:10'],
            'rules.*.field' => ['required', 'string'],
            'rules.*.op' => ['required', 'string'],
            'rules.*.value' => ['nullable'],
            'rules_match' => ['sometimes', Rule::in(['all', 'any'])],
        ]);
        foreach ($data['rules'] ?? [] as $i => $r) {
            if (! \App\Catalog\SmartCollections::validRule($r)) {
                throw ValidationException::withMessages(["rules.$i.value" => __('第 :p0 条规则不完整', ['p0' => $i + 1])]);
            }
        }

        if (! empty($data['image_id']) && ! StoreMedia::whereKey($data['image_id'])->exists()) {
            throw ValidationException::withMessages(['image_id' => __('图片不存在')]);
        }

        return $data;
    }

    private function uniqueHandle(string $source, ?int $ignoreId): string
    {
        $base = Str::slug($source) ?: 'collection';
        if ($base === 'all') {
            $base = 'all-1';   // "/collections/all" is reserved for "all products"
        }
        $handle = $base;
        $i = 1;
        while (Collection::where('handle', $handle)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $handle = $base.'-'.(++$i);
        }

        return $handle;
    }
}
