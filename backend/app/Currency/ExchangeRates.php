<?php

namespace App\Currency;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Daily exchange rates (stored per USD, so any pair can be derived). Fetched by the
 * `lumee:exchange-rates` command; if a fetch fails the previous rates stay in use.
 */
class ExchangeRates
{
    public const SOURCE = 'https://open.er-api.com/v6/latest/USD';

    /** @return array<string, float> currency => units per 1 USD */
    public function all(): array
    {
        return Cache::remember('exchange_rates', 3600, fn () => DB::table('exchange_rates')->pluck('per_usd', 'currency')
            ->map(fn ($v) => (float) $v)->put('USD', 1.0)->all());
    }

    /** Units of $to per 1 unit of $from, or null when either rate is unknown. */
    public function rate(string $from, string $to): ?float
    {
        if ($from === $to) {
            return 1.0;
        }
        $all = $this->all();
        if (empty($all[$from]) || empty($all[$to])) {
            return null;
        }

        return $all[$to] / $all[$from];
    }

    public function updatedAt(): ?string
    {
        return DB::table('exchange_rates')->max('updated_at');
    }

    /** Fetch fresh rates for the currencies Lumée supports. Returns how many were stored. */
    public function refresh(): int
    {
        $res = Http::timeout(15)->retry(2, 500)->get(self::SOURCE);
        $rates = $res->json('rates');
        if (! $res->successful() || $res->json('result') !== 'success' || ! is_array($rates)) {
            throw new RuntimeException('Exchange rate source returned no rates');
        }

        $now = now();
        $rows = [];
        foreach (config('lumee.currencies') as $code) {
            $v = $rates[$code] ?? null;
            if (is_numeric($v) && $v > 0) {
                $rows[] = ['currency' => $code, 'per_usd' => (float) $v, 'updated_at' => $now];
            }
        }
        DB::table('exchange_rates')->upsert($rows, ['currency'], ['per_usd', 'updated_at']);
        Cache::forget('exchange_rates');

        return count($rows);
    }
}
