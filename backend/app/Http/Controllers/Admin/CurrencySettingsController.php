<?php

namespace App\Http\Controllers\Admin;

use App\Currency\CurrencySettings;
use App\Currency\ExchangeRates;
use App\Http\Controllers\Controller;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Storefront multi-currency display (prices shown converted; checkout charges the store currency). */
class CurrencySettingsController extends Controller
{
    public function show(CurrentStore $current): JsonResponse
    {
        return response()->json($this->payload($current));
    }

    public function update(Request $request, CurrentStore $current): JsonResponse
    {
        $codes = config('lumee.currencies');
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'currencies' => ['present', 'array', 'max:20'],
            'currencies.*' => [Rule::in($codes)],
            'rounding' => ['required', Rule::in(CurrencySettings::ROUNDING)],
            'auto_detect' => ['boolean'],
            'rates' => ['sometimes', 'array'],
            'rates.*' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
        ]);
        if (array_diff_key($data['rates'] ?? [], array_flip($codes))) {
            throw ValidationException::withMessages(['rates' => __('包含不支持的货币')]);
        }
        if ($data['enabled'] && ! array_diff($data['currencies'], [$current->getOrFail()->currency])) {
            throw ValidationException::withMessages(['currencies' => __('请至少选择一种其他货币')]);
        }

        CurrencySettings::for($current->getOrFail())->save($data);

        return response()->json($this->payload($current));
    }

    /** Fetch today's rates now (also runs daily on the scheduler). */
    public function refreshRates(CurrentStore $current, ExchangeRates $rates): JsonResponse
    {
        try {
            $rates->refresh();
        } catch (Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['rates' => __('汇率更新失败，请稍后再试（会继续使用上次的汇率）')]);
        }

        return response()->json($this->payload($current));
    }

    private function payload(CurrentStore $current): array
    {
        $store = $current->getOrFail();
        $settings = CurrencySettings::for($store);
        $rates = app(ExchangeRates::class);

        return [
            'settings' => $settings->all(),
            'store_currency' => $store->currency,
            'currencies' => collect(config('lumee.currencies'))->reject(fn ($c) => $c === $store->currency)->map(fn ($c) => [
                'code' => $c,
                // Today's market rate (store currency → c); null when not fetched yet
                'market_rate' => ($r = $rates->rate($store->currency, $c)) !== null ? round($r, 6) : null,
            ])->values(),
            'rates_updated_at' => $rates->updatedAt(),
        ];
    }
}
