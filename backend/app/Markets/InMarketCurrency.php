<?php

namespace App\Markets;

/**
 * For models holding amounts the merchant entered in the store currency (shipping rate prices,
 * fixed discounts, thresholds): inside a market they read in the market currency.
 * Saving and API serialization keep the raw store-currency amounts.
 */
trait InMarketCurrency
{
    protected function inMarketCurrency(string $key, mixed $value): mixed
    {
        if (! is_int($value) || ! in_array($key, $this->marketMoneyAttributes(), true)) {
            return $value;
        }
        $markets = app(MarketContext::class);

        $storeId = $this->attributes['store_id'] ?? null;   // not always selected

        return $markets->activeFor($storeId === null ? null : (int) $storeId) ? $markets->money($value) : $value;
    }

    /** @return string[] */
    abstract protected function marketMoneyAttributes(): array;
}
