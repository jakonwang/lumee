<?php

namespace App\Collect;

use App\Currency\ExchangeRates;
use App\Models\Market;
use App\Models\PricingRule;
use App\Models\Store;
use RuntimeException;

/**
 * Selling prices from collected costs. The cost (source currency) is converted to the store
 * currency with today's rate, then the first matching rule applies:
 *   price = cost × multiplier + add, with a price ending; compare-at = price × compare multiplier.
 * Without a rule, the source price is kept (converted). Markets then convert from the store price.
 */
class Pricing
{
    /** @throws RuntimeException when the source currency has no exchange rate */
    public function apply(Store $store, array $d, ?PricingRule $rule = null): array
    {
        $base = $store->baseCurrency();
        $from = $d['currency'] ?? $base;
        $rate = app(ExchangeRates::class)->rate($from, $base);
        if ($rate === null) {
            throw new RuntimeException("还没有 {$from} 的汇率，无法换算成本（请在「设置 › 多币种」更新汇率）");
        }
        $rule ??= $this->ruleFor($d);

        foreach ($d['variants'] as $i => $v) {
            $cost = (int) round(((int) $v['cost']) * $rate);
            if ($rule) {
                $price = Market::round((int) round($cost * $rule->multiplier) + $rule->add_amount, $rule->rounding);
                $compare = $rule->compare_multiplier ? Market::round((int) round($price * $rule->compare_multiplier), $rule->rounding) : null;
            } else {
                $price = $cost;
                $compare = $v['compare_cost'] ? (int) round($v['compare_cost'] * $rate) : null;
            }
            $d['variants'][$i]['price'] = $price;
            $d['variants'][$i]['compare_at_price'] = $compare !== null && $compare > $price ? $compare : null;
        }
        $d['pricing'] = ['rule' => $rule?->name, 'rule_id' => $rule?->id, 'rate' => round($rate, 6)];

        return $d;
    }

    /** First rule (by position) whose scope matches: a product type, a tag, or everything. */
    public function ruleFor(array $d): ?PricingRule
    {
        $tags = array_map('mb_strtolower', (array) ($d['tags'] ?? []));
        $type = mb_strtolower((string) ($d['product_type'] ?? ''));

        return PricingRule::orderBy('position')->orderBy('id')->get()->first(fn (PricingRule $r) => match ($r->scope) {
            'product_type' => $type !== '' && $type === mb_strtolower((string) $r->scope_value),
            'tag' => in_array(mb_strtolower((string) $r->scope_value), $tags, true),
            default => true,
        });
    }
}
