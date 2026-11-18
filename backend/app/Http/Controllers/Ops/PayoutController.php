<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\PartnerPayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Ops: developer payouts to send, and recording them as paid. */
class PayoutController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'pending');

        return response()->json([
            'data' => PartnerPayout::with('user:id,name,email')->when(in_array($status, ['pending', 'paid'], true), fn ($q) => $q->where('status', $status))
                ->latest('id')->limit(200)->get()->map(fn ($p) => [
                    'id' => $p->id, 'period' => $p->period, 'amount' => number_format($p->amount / 100, 2, '.', ''), 'status' => $p->status,
                    'method' => $p->method, 'details' => $p->details, 'reference' => $p->reference, 'paid_at' => $p->paid_at,
                    'developer' => $p->user ? ['name' => $p->user->name, 'email' => $p->user->email] : null,
                ]),
            'pending_total' => number_format(PartnerPayout::where('status', 'pending')->sum('amount') / 100, 2, '.', ''),
        ]);
    }

    public function markPaid(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:120']], ['reference.required' => __('请填写转账流水号')]);
        $payout = PartnerPayout::findOrFail($id);
        if ($payout->status === 'paid') {
            throw ValidationException::withMessages(['reference' => __('这笔结算已经标记为已支付')]);
        }
        $payout->forceFill(['status' => 'paid', 'reference' => $data['reference'], 'paid_at' => now()])->save();

        return response()->json(['ok' => true]);
    }
}
