<?php

namespace App\Subscriptions;

use App\Models\Product;
use Carbon\CarbonInterface;

/**
 * Subscribe-and-save options on a product (products.subscription):
 *   {enabled, discount (% off each delivery), intervals: ["week:2","month:1"], only: subscription-only product}
 * A plan is "unit:count", e.g. "month:1" = every month.
 */
class Plans
{
    public const INTERVALS = ['week:1', 'week:2', 'week:3', 'week:4', 'month:1', 'month:2', 'month:3', 'month:6'];

    public static function config(?Product $product): array
    {
        $c = $product?->subscription ?? [];
        $intervals = array_values(array_intersect(self::INTERVALS, (array) ($c['intervals'] ?? [])));

        return [
            'enabled' => (bool) ($c['enabled'] ?? false) && $intervals !== [],
            'discount' => max(0, min(90, (float) ($c['discount'] ?? 0))),
            'intervals' => $intervals,
            'only' => (bool) ($c['only'] ?? false),
        ];
    }

    /** Clean the editor's input before saving it on the product. */
    public static function clean(?array $input): ?array
    {
        if (! $input || ! ($input['enabled'] ?? false)) {
            return $input ? ['enabled' => false, 'discount' => (float) ($input['discount'] ?? 0), 'intervals' => array_values(array_intersect(self::INTERVALS, (array) ($input['intervals'] ?? []))), 'only' => false] : null;
        }

        return [
            'enabled' => true,
            'discount' => max(0, min(90, round((float) ($input['discount'] ?? 0), 2))),
            'intervals' => array_values(array_intersect(self::INTERVALS, (array) ($input['intervals'] ?? []))) ?: ['month:1'],
            'only' => (bool) ($input['only'] ?? false),
        ];
    }

    public static function allowed(?Product $product, ?string $plan): bool
    {
        $c = self::config($product);
        if ($plan === null || $plan === '') {
            return ! ($c['enabled'] && $c['only']);
        }

        return $c['enabled'] && in_array($plan, $c['intervals'], true);
    }

    /** Price for one delivery on a plan. */
    public static function price(?Product $product, int $price, ?string $plan): int
    {
        if (! $plan) {
            return $price;
        }
        $d = self::config($product)['discount'];

        return $d > 0 ? (int) round($price * (100 - $d) / 100) : $price;
    }

    public static function label(?string $plan): string
    {
        if (! $plan || ! str_contains($plan, ':')) {
            return '';
        }
        [$unit, $n] = explode(':', $plan);
        $n = (int) $n;

        return $unit === 'week'
            ? trans_choice('Delivered every week|Delivered every :count weeks', $n, ['count' => $n])
            : trans_choice('Delivered every month|Delivered every :count months', $n, ['count' => $n]);
    }

    public static function next(CarbonInterface $from, string $plan): CarbonInterface
    {
        [$unit, $n] = explode(':', $plan);

        return $unit === 'week' ? $from->copy()->addWeeks((int) $n) : $from->copy()->addMonthsNoOverflow((int) $n);
    }
}
