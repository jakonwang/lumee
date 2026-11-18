<?php

namespace App\Http\Controllers\Admin;

use App\Billing\PartnerPayouts;
use App\Http\Controllers\Controller;
use App\Models\PartnerPayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Developers: earnings balance, payout history, and where to send the money. */
class PartnerPayoutController extends Controller
{
    public function show(Request $request, PartnerPayouts $payouts): JsonResponse
    {
        $user = $request->user();
        $b = $payouts->balance($user->id);
        $money = fn (int $c) => number_format($c / 100, 2, '.', '');
        $d = $user->payout_details ?? [];

        return response()->json([
            'balance' => ['pending' => $money($b['pending']), 'available' => $money($b['available']), 'paid' => $money($b['paid'])],
            'minimum' => $money(PartnerPayouts::MINIMUM),
            'details' => ['method' => $d['method'] ?? null, 'account' => isset($d['account']) ? $this->mask($d['account']) : null],
            'payouts' => PartnerPayout::where('user_id', $user->id)->latest('id')->limit(36)->get()->map(fn ($p) => [
                'id' => $p->id, 'period' => $p->period, 'amount' => $money($p->amount), 'status' => $p->status,
                'method' => $p->method, 'reference' => $p->reference, 'paid_at' => $p->paid_at,
            ]),
        ]);
    }

    public function updateDetails(Request $request): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', Rule::in(['paypal', 'bank'])],
            'account' => ['required', 'string', 'max:500', $request->input('method') === 'paypal' ? 'email' : 'min:8'],
        ], ['account.email' => __('请填写 PayPal 账户邮箱')]);
        $request->user()->forceFill(['payout_details' => ['method' => $data['method'], 'account' => trim($data['account'])]])->save();

        return $this->show($request, app(PartnerPayouts::class));
    }

    /** Only the last few characters are ever sent back. */
    private function mask(string $s): string
    {
        $s = trim($s);
        if (str_contains($s, '@')) {
            [$name, $domain] = explode('@', $s, 2);

            return mb_substr($name, 0, 2).'***@'.$domain;
        }

        return '•••• '.mb_substr(preg_replace('/\s+/', '', $s), -4);
    }
}
