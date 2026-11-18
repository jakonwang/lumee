<?php

namespace App\Http\Controllers\Admin;

use App\Billing\Billing;
use App\Currency\ExchangeRates;
use App\Http\Controllers\Controller;
use App\Http\Resources\StoreResource;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Services\ProductCopier;
use App\Services\StoreProvisioner;
use App\Support\Money;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Everything across the stores a user belongs to: create stores, compare them, copy products between them. */
class OrganizationController extends Controller
{
    /** Stores one account can own (each has its own trial and plan). */
    public const MAX_OWNED = 10;

    public function createStore(Request $request, StoreProvisioner $provisioner): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'currency' => ['nullable', Rule::in(config('lumee.currencies'))],
        ], ['name.required' => __('请填写店铺名称')]);
        if (Store::where('owner_id', $user->id)->count() >= self::MAX_OWNED) {
            throw ValidationException::withMessages(['name' => __('每个账号最多拥有 :p0 家店铺', ['p0' => self::MAX_OWNED])]);
        }
        $store = $provisioner->create($user, $data['name'], array_filter(['currency' => $data['currency'] ?? null]));

        return response()->json([
            'data' => (new StoreResource($user->stores()->with('domains')->whereKey($store->id)->first()))->resolve(),
            'stores' => StoreResource::collection($user->stores()->with('domains')->get()),
        ], 201);
    }

    /** Sales and status of every store the user may see analytics for, plus totals in one currency. */
    public function overview(Request $request, Billing $billing, ExchangeRates $rates): JsonResponse
    {
        $data = $request->validate([
            'days' => ['nullable', Rule::in([7, 30, 90])],
            'currency' => ['nullable', Rule::in(config('lumee.currencies'))],
        ]);
        $days = (int) ($data['days'] ?? 30);
        $currency = $data['currency'] ?? 'USD';
        $since = now()->subDays($days);

        $stores = $this->visible($request->user(), 'analytics');
        $ids = $stores->pluck('id')->all();
        $orders = Order::withoutGlobalScope('store')->whereIn('store_id', $ids)->where('created_at', '>=', $since)
            ->whereIn('financial_status', Order::PAID_STATUSES)
            ->get(['id', 'store_id', 'total', 'refunded_total', 'created_at', 'customer_id']);
        $today = now()->startOfDay();

        $rows = $stores->map(function (Store $s) use ($orders, $billing, $today) {
            $mine = $orders->where('store_id', $s->id);
            $revenue = (int) $mine->sum(fn ($o) => $o->total - $o->refunded_total);

            return [
                'id' => $s->id, 'name' => $s->name, 'handle' => $s->handle, 'currency' => $s->currency, 'url' => $s->storefrontUrl(),
                'role' => $s->pivot->role, 'billing' => $billing->brief($s),
                'revenue_minor' => $revenue,
                'revenue' => Money::format($revenue),
                'orders' => $mine->count(),
                'aov' => Money::format($mine->count() ? (int) round($revenue / $mine->count()) : 0),
                'today' => Money::format((int) $mine->filter(fn ($o) => $o->created_at >= $today)->sum(fn ($o) => $o->total - $o->refunded_total)),
            ];
        })->values();

        // Totals in the reporting currency (a store whose rate is missing is left out and flagged)
        $total = 0;
        $missing = [];
        foreach ($rows as $r) {
            $rate = $rates->rate($r['currency'], $currency);
            $rate === null ? $missing[] = $r['currency'] : $total += (int) round($r['revenue_minor'] * $rate);
        }

        $recent = Order::withoutGlobalScope('store')->whereIn('store_id', $this->visible($request->user(), 'orders')->pluck('id'))
            ->latest('id')->limit(10)->get(['id', 'store_id', 'number', 'email', 'total', 'currency', 'financial_status', 'fulfillment_status', 'created_at']);
        $names = $stores->pluck('name', 'id');

        return response()->json([
            'stores' => $rows->map(fn ($r) => collect($r)->except('revenue_minor'))->values(),
            'totals' => [
                'currency' => $currency, 'revenue' => Money::format($total), 'orders' => $rows->sum('orders'),
                'missing_rates' => array_values(array_unique($missing)),
            ],
            'recent_orders' => $recent->map(fn (Order $o) => [
                'id' => $o->id, 'store_id' => $o->store_id, 'store' => $names[$o->store_id] ?? Store::find($o->store_id)?->name,
                'name' => $o->name(), 'email' => $o->email, 'total' => Money::format($o->total), 'currency' => $o->currency,
                'financial_status' => $o->financial_status, 'fulfillment_status' => $o->fulfillment_status, 'created_at' => $o->created_at,
            ]),
            'days' => $days,
            'can_create' => Store::where('owner_id', $request->user()->id)->count() < self::MAX_OWNED,
        ]);
    }

    /** Copy products from the current store to another store the user can manage products in. */
    public function copyProducts(Request $request, CurrentStore $current, ProductCopier $copier): JsonResponse
    {
        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1', 'max:'.ProductCopier::MAX],
            'product_ids.*' => ['integer'],
            'target_store_id' => ['required', 'integer'],
            'price_mode' => ['nullable', Rule::in(['same', 'convert'])],
            'inventory' => ['nullable', 'boolean'],
            'images' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::in(['draft', 'same'])],
        ], ['product_ids.required' => __('请先选择商品')]);

        $source = $current->getOrFail();
        $target = $this->visible($request->user(), 'products')->firstWhere('id', (int) $data['target_store_id']);
        if (! $target || $target->id === $source->id) {
            throw ValidationException::withMessages(['target_store_id' => __('请选择另一家你有商品权限的店铺')]);
        }
        if (! in_array(app(Billing::class)->state($target), Billing::OPEN, true)) {
            throw ValidationException::withMessages(['target_store_id' => __('目标店铺已暂停营业，请先选择套餐')]);
        }

        $results = $copier->copy($source, $target, array_map('intval', $data['product_ids']), $data);

        return response()->json([
            'data' => $results,
            'copied' => count(array_filter($results, fn ($r) => $r['product_id'])),
            'target' => ['id' => $target->id, 'name' => $target->name],
        ]);
    }

    /** Stores the user belongs to where they have $perm (owners have everything). @return Collection<int, Store> */
    private function visible(User $user, string $perm): Collection
    {
        return $user->stores()->with('domains')->get()->filter(function (Store $s) use ($perm) {
            if ($s->pivot->role === 'owner') {
                return true;
            }
            $perms = $s->pivot->permissions ?? [];
            $perms = is_array($perms) ? $perms : (json_decode((string) $perms, true) ?: []);

            return in_array($perm, $perms, true);
        })->values();
    }
}
