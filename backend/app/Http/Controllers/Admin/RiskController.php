<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlocklistEntry;
use App\Models\Order;
use App\Risk\RiskAnalyzer;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Fraud settings, the checkout blocklist, and reviewing a held order. */
class RiskController extends Controller
{
    public function show(CurrentStore $current): JsonResponse
    {
        return response()->json($this->payload($current));
    }

    public function update(Request $request, CurrentStore $current): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'hold_high' => ['required', 'boolean']]);
        $store = $current->getOrFail();
        $settings = $store->settings ?? [];
        $settings['risk'] = ['enabled' => (bool) $data['enabled'], 'hold_high' => (bool) $data['hold_high']];
        $store->settings = $settings;
        $store->save();

        return response()->json($this->payload($current));
    }

    public function block(Request $request, CurrentStore $current): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(BlocklistEntry::TYPES)],
            'value' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $value = BlocklistEntry::normalize($data['type'], $data['value']);
        $valid = match ($data['type']) {
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'domain' => (bool) preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $value),
            'ip' => filter_var($value, FILTER_VALIDATE_IP) !== false,
        };
        if (! $valid) {
            throw ValidationException::withMessages(['value' => __('格式不正确')]);
        }
        BlocklistEntry::firstOrCreate(['type' => $data['type'], 'value' => $value], ['store_id' => $current->id(), 'note' => $data['note'] ?? null]);

        return response()->json($this->payload($current), 201);
    }

    public function unblock(int $id, CurrentStore $current): JsonResponse
    {
        BlocklistEntry::findOrFail($id)->delete();   // store-scoped

        return response()->json($this->payload($current));
    }

    /** The merchant checked a held order: fulfilment can go ahead. */
    public function release(Order $order): JsonResponse
    {
        if ($order->risk_hold) {
            $order->forceFill(['risk_hold' => false])->save();
            $order->log('risk', __('商家已核实订单，解除发货暂停'));
        }

        return response()->json(['ok' => true]);
    }

    private function payload(CurrentStore $current): array
    {
        return [
            'settings' => RiskAnalyzer::settings($current->getOrFail()),
            'blocklist' => BlocklistEntry::latest('id')->limit(500)->get(['id', 'type', 'value', 'note', 'hits', 'created_at']),
            'held' => Order::where('risk_hold', true)->whereNull('cancelled_at')->count(),
            'high_30d' => Order::where('risk_level', 'high')->where('created_at', '>=', now()->subDays(30))->count(),
        ];
    }
}
