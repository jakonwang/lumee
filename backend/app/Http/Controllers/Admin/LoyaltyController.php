<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Loyalty\Points;
use App\Models\Customer;
use App\Models\PointTransaction;
use App\Support\Money;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Marketing › Points: the program's rules, totals and top members; per-customer adjustments. */
class LoyaltyController extends Controller
{
    public function show(CurrentStore $current): JsonResponse
    {
        return response()->json($this->payload($current));
    }

    public function update(Request $request, CurrentStore $current): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'earn_rate' => ['required', 'numeric', 'min:0', 'max:1000'],
            'redeem_points' => ['required', 'integer', 'min:1', 'max:100000'],
            'min_redeem' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);
        $store = $current->getOrFail();
        $store->settings = [...($store->settings ?? []), 'loyalty' => $data];
        $store->save();

        return response()->json($this->payload($current));
    }

    /** Customer page: history + manual adjustment (goodwill, corrections). */
    public function customer(int $id): JsonResponse
    {
        $c = Customer::findOrFail($id);

        return response()->json(['points' => (int) $c->points, 'history' => $this->history($c)]);
    }

    public function adjust(Request $request, int $id, Points $points): JsonResponse
    {
        $data = $request->validate(['points' => ['required', 'integer', 'min:-1000000', 'max:1000000', 'not_in:0'], 'note' => ['nullable', 'string', 'max:200']]);
        $c = Customer::findOrFail($id);
        $points->adjust($c, (int) $data['points'], $data['note'] ?? null);

        return $this->customer($c->id);
    }

    private function history(Customer $c): array
    {
        return PointTransaction::with('user:id,name')->where('customer_id', $c->id)->latest('id')->limit(50)->get()
            ->map(fn ($t) => ['id' => $t->id, 'points' => $t->points, 'balance_after' => $t->balance_after, 'kind' => $t->kind,
                'order_id' => $t->order_id, 'note' => $t->note, 'user' => $t->user?->name, 'created_at' => $t->created_at])->all();
    }

    private function payload(CurrentStore $current): array
    {
        $store = $current->getOrFail()->fresh();
        $s = Points::settings($store);
        $outstanding = (int) Customer::where('points', '>', 0)->sum('points');

        return [
            'settings' => $s,
            'currency' => $store->currency,
            'stats' => [
                'members' => Customer::where('points', '>', 0)->count(),
                'outstanding' => $outstanding,
                'outstanding_value' => Money::format(Points::value($store, $outstanding)),
                'earned_30d' => (int) PointTransaction::where('kind', 'earned')->where('created_at', '>=', now()->subDays(30))->sum('points'),
                'redeemed_30d' => -(int) PointTransaction::where('kind', 'redeemed')->where('created_at', '>=', now()->subDays(30))->sum('points'),
            ],
            'top' => Customer::where('points', '>', 0)->orderByDesc('points')->limit(10)->get(['id', 'email', 'first_name', 'last_name', 'points'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->fullName() ?: $c->email, 'email' => $c->email, 'points' => (int) $c->points]),
        ];
    }
}
