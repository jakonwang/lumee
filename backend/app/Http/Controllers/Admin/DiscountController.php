<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Discount;
use App\Support\Money;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DiscountController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Discount::latest('id')->get()->map(fn ($d) => $this->present($d)),
            'types' => \App\Support\Tr::map(Discount::TYPES),
        ]);
    }

    public function store(Request $request, CurrentStore $current): JsonResponse
    {
        $discount = Discount::create($this->validated($request, $current));

        return response()->json(['data' => $this->present($discount)], 201);
    }

    public function update(Request $request, Discount $discount, CurrentStore $current): JsonResponse
    {
        $discount->update($this->validated($request, $current, $discount));

        return response()->json(['data' => $this->present($discount->fresh())]);
    }

    public function destroy(Discount $discount)
    {
        $discount->delete();   // past orders keep the code they used (stored on the order)

        return response()->noContent();
    }

    private function present(Discount $d): array
    {
        return [
            'id' => $d->id,
            'code' => $d->code,
            'type' => $d->type,
            // percentage → whole number; fixed → money string
            'value' => $d->type === 'fixed' ? Money::format($d->value) : $d->value,
            'min_subtotal' => $d->min_subtotal !== null ? Money::format($d->min_subtotal) : null,
            'usage_limit' => $d->usage_limit,
            'usage_count' => $d->usage_count,
            'once_per_customer' => $d->once_per_customer,
            'active' => $d->active,
            'starts_at' => $d->starts_at,
            'ends_at' => $d->ends_at,
            'status' => $d->status(),
            'summary' => $d->label(app(CurrentStore::class)->getOrFail()->currency),
        ];
    }

    private function validated(Request $request, CurrentStore $current, ?Discount $existing = null): array
    {
        $request->merge(['code' => Discount::normalizeCode((string) $request->input('code'))]);

        $data = $request->validate([
            'code' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('discounts', 'code')->where('store_id', $current->id())->ignore($existing?->id)],
            'type' => ['required', Rule::in(array_keys(Discount::TYPES))],
            'value' => ['nullable', 'numeric', 'min:0'],
            'min_subtotal' => ['nullable', 'numeric', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'once_per_customer' => ['boolean'],
            'active' => ['boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ], [
            'code.regex' => __('折扣码只能包含字母、数字、- 和 _'),
            'code.unique' => __('这个折扣码已经存在'),
            'code.min' => __('折扣码至少 3 个字符'),
            'ends_at.after' => __('结束时间必须晚于开始时间'),
        ]);

        $value = (float) ($data['value'] ?? 0);
        if ($data['type'] === 'percentage' && ($value < 1 || $value > 100 || floor($value) != $value)) {
            throw ValidationException::withMessages(['value' => __('折扣百分比应为 1–100 的整数')]);
        }
        if ($data['type'] === 'fixed' && $value <= 0) {
            throw ValidationException::withMessages(['value' => __('请填写减免金额')]);
        }

        return [
            'code' => $data['code'],
            'type' => $data['type'],
            'value' => match ($data['type']) {
                'percentage' => (int) $value,
                'fixed' => Money::toMinor($value),
                default => 0,
            },
            'min_subtotal' => isset($data['min_subtotal']) ? Money::toMinor($data['min_subtotal']) : null,
            'usage_limit' => $data['usage_limit'] ?? null,
            'once_per_customer' => (bool) ($data['once_per_customer'] ?? false),
            'active' => (bool) ($data['active'] ?? true),
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
        ];
    }
}
