<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Marketing\Affiliates;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Support\Money;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Marketing › Affiliates: partners, their links and commissions, programme settings. */
class AffiliateController extends Controller
{
    public function index(CurrentStore $current): JsonResponse
    {
        $store = $current->getOrFail();
        $money = fn (int $c) => Money::format($c);
        $rows = Affiliate::latest('id')->get();

        return response()->json([
            'data' => $rows->map(function (Affiliate $a) use ($store, $money) {
                $s = Affiliates::stats($store, $a);

                return [
                    'id' => $a->id, 'name' => $a->name, 'email' => $a->email, 'code' => $a->code, 'commission_rate' => $a->commission_rate,
                    'status' => $a->status, 'note' => $a->note, 'link' => $store->storefrontUrl().'/?ref='.$a->code,
                    'portal_url' => $store->storefrontUrl().'/affiliate/'.$a->token,
                    'stats' => [...$s, 'sales' => $money($s['sales']), 'pending' => $money($s['pending']), 'payable' => $money($s['payable']), 'paid' => $money($s['paid'])],
                ];
            }),
            'settings' => Affiliates::settings($store),
            'currency' => $store->currency,
        ]);
    }

    public function store(Request $request, CurrentStore $current): JsonResponse
    {
        $data = $this->validated($request);
        $a = new Affiliate($data);
        $a->forceFill(['store_id' => $current->id(), 'token' => Str::random(48), 'status' => 'active'])->save();

        return $this->index($current)->setStatusCode(201);
    }

    public function update(Request $request, int $id, CurrentStore $current): JsonResponse
    {
        $a = Affiliate::findOrFail($id);
        $a->fill($this->validated($request, $a->id))->save();

        return $this->index($current);
    }

    public function destroy(int $id, CurrentStore $current): JsonResponse
    {
        $a = Affiliate::findOrFail($id);
        if (AffiliateCommission::where('affiliate_id', $a->id)->where('status', 'pending')->where('amount', '>', 0)->exists()) {
            throw ValidationException::withMessages(['affiliate' => __('还有未结算的佣金，请先结算或暂停这个推广员')]);
        }
        $a->delete();

        return $this->index($current);
    }

    /** Mark everything payable (past the return window) as paid. */
    public function pay(Request $request, int $id, CurrentStore $current): JsonResponse
    {
        $data = $request->validate(['reference' => ['nullable', 'string', 'max:120']]);
        $a = Affiliate::findOrFail($id);
        $n = Affiliates::payableQuery($current->getOrFail(), $a->id)->update(['status' => 'paid', 'paid_at' => now(), 'payout_reference' => $data['reference'] ?? null]);
        if (! $n) {
            throw ValidationException::withMessages(['affiliate' => __('没有可以结算的佣金（退货期内的佣金要等到期后才能结算）')]);
        }

        return $this->index($current);
    }

    public function commissions(int $id): JsonResponse
    {
        $a = Affiliate::findOrFail($id);

        return response()->json(['data' => AffiliateCommission::where('affiliate_id', $a->id)->latest('id')->limit(200)->get()->map(fn ($c) => [
            'id' => $c->id, 'order_id' => $c->order_id, 'order_name' => \App\Models\Order::find($c->order_id)?->name(),
            'base_amount' => Money::format($c->base_amount), 'amount' => Money::format($c->amount), 'rate' => $c->rate,
            'status' => $c->status, 'created_at' => $c->created_at, 'paid_at' => $c->paid_at, 'payout_reference' => $c->payout_reference,
        ])]);
    }

    public function settings(Request $request, CurrentStore $current): JsonResponse
    {
        $data = $request->validate([
            'cookie_days' => ['required', 'integer', 'min:1', 'max:365'],
            'return_days' => ['required', 'integer', 'min:0', 'max:180'],
            'default_rate' => ['required', 'numeric', 'min:0', 'max:90'],
        ]);
        $store = $current->getOrFail();
        $settings = $store->settings ?? [];
        $settings['affiliates'] = $data;
        $store->settings = $settings;
        $store->save();

        return $this->index($current);
    }

    private function validated(Request $request, ?int $ignore = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'string', 'min:2', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'commission_rate' => ['required', 'numeric', 'min:0', 'max:90'],
            'status' => ['sometimes', Rule::in(['active', 'paused'])],
            'note' => ['nullable', 'string', 'max:300'],
        ], ['code.regex' => __('推广码只能包含字母、数字、- 和 _')]);
        $data['code'] = mb_strtolower($data['code']);
        $data['email'] = mb_strtolower(trim($data['email']));
        if (Affiliate::where('code', $data['code'])->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->exists()) {
            throw ValidationException::withMessages(['code' => __('这个推广码已经被使用')]);
        }

        return $data;
    }
}
