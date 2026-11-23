<?php

namespace App\Analytics;

use App\Currency\ExchangeRates;
use App\Models\AdSpend;
use App\Models\Market;
use App\Models\Store;
use App\Support\Countries;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Ad spend from the CSV report each platform exports (Google Ads, Meta Ads Manager, TikTok Ads Manager,
 * Microsoft Ads…), English or Chinese column names:
 *
 *   date        Day / Date / Reporting starts / By Day / 日期 / 天
 *   campaign    Campaign / Campaign name / 广告系列 / 广告系列名称 / 推广系列名称
 *   spend       Cost / Amount spent (USD) / Spend / Total cost / 费用 / 花费 / 已花费金额 / 消耗
 *   currency    Currency / Currency code / 货币 / 币种 — or "(USD)" in the spend header — else the store currency
 *   country     Country / Country/Territory / Region / 国家/地区 / 国家 → the market that sells there
 *   impressions Impressions / Impr. / 展示次数 / 曝光量;  clicks  Clicks / Link clicks / 点击次数 / 点击量
 *
 * Amounts are converted to the store currency. Re-importing the same report replaces its lines
 * (same day, platform, market and campaign) instead of adding them twice.
 */
class AdSpendImport
{
    private const COLUMNS = [
        'date' => ['day', 'date', 'reporting starts', 'by day', 'start date', '日期', '天', '开始日期'],
        'campaign' => ['campaign', 'campaign name', 'ad set name', '广告系列', '广告系列名称', '推广系列名称', '广告组名称'],
        'spend' => ['cost', 'amount spent', 'spend', 'total cost', 'cost (usd)', '费用', '花费', '已花费金额', '消耗', '成本'],
        'currency' => ['currency', 'currency code', '货币', '币种', '货币代码'],
        'country' => ['country', 'country/territory', 'country/region', 'region', '国家/地区', '国家', '地区'],
        'impressions' => ['impressions', 'impr.', 'impr', '展示次数', '曝光量', '展示量'],
        'clicks' => ['clicks', 'link clicks', 'clicks (all)', '点击次数', '点击量', '链接点击量'],
    ];

    public function __construct(private ExchangeRates $rates) {}

    /** @return array{imported: int, skipped: int, spend: int, from: ?string, to: ?string, markets: int} */
    public function import(Store $store, string $platform, string $csv): array
    {
        $rows = $this->rows($csv);
        if (count($rows) < 2) {
            throw new RuntimeException('文件里没有数据行');
        }
        // Google Ads exports start with a report title and a date range line: find the header row
        $headerAt = null;
        foreach (array_slice($rows, 0, 8, true) as $i => $r) {
            if ($this->map($r)['spend'] !== null) {
                $headerAt = $i;
                break;
            }
        }
        if ($headerAt === null) {
            throw new RuntimeException('没有找到“费用 / Cost / Amount spent”列，请导出包含花费的报表');
        }
        $header = $rows[$headerAt];
        $cols = $this->map($header);
        if ($cols['date'] === null) {
            throw new RuntimeException('没有找到“日期 / Day”列，请按天细分后再导出');
        }
        $headerCurrency = preg_match('/\(([A-Z]{3})\)/', (string) $header[$cols['spend']], $m) ? $m[1] : null;
        $markets = Market::get();
        $base = $store->baseCurrency();

        $lines = [];
        $skipped = 0;
        foreach (array_slice($rows, $headerAt + 1) as $r) {
            $date = $this->date($r[$cols['date']] ?? '');
            $amount = $this->number($r[$cols['spend']] ?? '');
            if (! $date || $amount === null) {
                $skipped++;   // totals lines, blank lines

                continue;
            }
            $currency = strtoupper(trim((string) ($cols['currency'] !== null ? $r[$cols['currency']] ?? '' : ''))) ?: ($headerCurrency ?? $base);
            if (! preg_match('/^[A-Z]{3}$/', $currency)) {
                $currency = $base;
            }
            $rate = $currency === $base ? 1.0 : $this->rates->rate($currency, $base);
            if ($rate === null) {
                throw new RuntimeException("没有 {$currency} 的汇率，无法换算成店铺货币");
            }
            $market = $cols['country'] !== null ? $this->market($markets, (string) ($r[$cols['country']] ?? '')) : null;
            $campaign = Str::limit(trim((string) ($cols['campaign'] !== null ? $r[$cols['campaign']] ?? '' : '')), 150, '');
            $key = $date.'|'.($market?->id ?? 0).'|'.$campaign;
            $line = $lines[$key] ?? ['date' => $date, 'market_id' => $market?->id, 'campaign' => $campaign, 'currency' => $currency, 'amount' => 0, 'spend' => 0, 'impressions' => 0, 'clicks' => 0];
            $line['amount'] += (int) round($amount * 100);
            $line['spend'] += (int) round($amount * 100 * $rate);
            $line['impressions'] += (int) ($cols['impressions'] !== null ? $this->number($r[$cols['impressions']] ?? '') : 0);
            $line['clicks'] += (int) ($cols['clicks'] !== null ? $this->number($r[$cols['clicks']] ?? '') : 0);
            $lines[$key] = $line;
        }
        foreach ($lines as $l) {
            AdSpend::updateOrCreate(['date' => $l['date'], 'platform' => $platform, 'market_id' => $l['market_id'], 'campaign' => $l['campaign']],
                [...$l, 'source' => 'import']);
        }
        $dates = array_column($lines, 'date');
        sort($dates);

        return ['imported' => count($lines), 'skipped' => $skipped, 'spend' => array_sum(array_column($lines, 'spend')),
            'from' => $dates[0] ?? null, 'to' => $dates ? end($dates) : null, 'markets' => count(array_unique(array_filter(array_column($lines, 'market_id'))))];
    }

    /** @return array<int, string[]> */
    private function rows(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        if (str_starts_with($csv, "\xFF\xFE") || str_starts_with($csv, "\xFE\xFF")) {
            $csv = mb_convert_encoding($csv, 'UTF-8', 'UTF-16');   // Google Ads "CSV" downloads are UTF-16 with tabs
        }
        $lines = preg_split('/\r\n|\n|\r/', trim($csv));
        $delimiter = substr_count($lines[0] ?? '', "\t") > substr_count($lines[0] ?? '', ',') ? "\t" : (substr_count($lines[0] ?? '', ';') > substr_count($lines[0] ?? '', ',') ? ';' : ',');
        // The header may be a few lines down: pick the delimiter that splits most lines
        foreach (array_slice($lines, 0, 8) as $l) {
            if (substr_count($l, "\t") >= 2) {
                $delimiter = "\t";
                break;
            }
        }

        return array_values(array_filter(array_map(fn ($l) => str_getcsv($l, $delimiter), $lines), fn ($r) => count(array_filter($r, fn ($v) => trim((string) $v) !== '')) > 0));
    }

    /** @return array<string, ?int> column index per field */
    private function map(array $header): array
    {
        $out = array_fill_keys(array_keys(self::COLUMNS), null);
        foreach ($header as $i => $h) {
            $name = mb_strtolower(trim(preg_replace('/\s*\([^)]*\)\s*$/u', '', (string) $h)));
            foreach (self::COLUMNS as $field => $names) {
                if ($out[$field] === null && in_array($name, $names, true)) {
                    $out[$field] = $i;
                }
            }
        }

        return $out;
    }

    private function date(string $v): ?string
    {
        $v = trim($v);
        if ($v === '' || preg_match('/^(total|总计|合计)/iu', $v)) {
            return null;
        }
        // Exact formats first (ISO, US month/day, Chinese); "Nov 3, 2026" and the like through the parser
        foreach (['Y-m-d', 'Y/m/d', 'm/d/Y', 'd.m.Y', 'Y年n月j日'] as $f) {
            $d = rescue(fn () => CarbonImmutable::createFromFormat('!'.$f, $v), null, false);
            if ($d && $d->format($f) === $v) {
                return $d->toDateString();
            }
        }

        return preg_match('/[a-z]{3}/i', $v) ? rescue(fn () => CarbonImmutable::parse($v)->toDateString(), null, false) : null;
    }

    /** "1,234.56", "1.234,56", "$1,234", "¥30" → float */
    private function number(string $v): ?float
    {
        $v = trim(preg_replace('/[^\d.,\-]/u', '', $v));
        if ($v === '' || $v === '-' || $v === '--') {
            return null;
        }
        if (preg_match('/,\d{1,2}$/', $v) && str_contains($v, '.')) {
            $v = str_replace(['.', ','], ['', '.'], $v);   // 1.234,56
        } elseif (preg_match('/,\d{1,2}$/', $v)) {
            $v = str_replace(',', '.', $v);                // 12,50
        } else {
            $v = str_replace(',', '', $v);                 // 1,234.56
        }

        return is_numeric($v) ? (float) $v : null;
    }

    /** A country name or code from the report → the market selling there (null when none or several). */
    private function market($markets, string $country): ?Market
    {
        $country = trim($country);
        if ($country === '') {
            return null;
        }
        $code = strlen($country) === 2 ? strtoupper($country) : array_search(mb_strtolower($country), array_map('mb_strtolower', Countries::all()), true);
        if (! $code) {
            return null;
        }

        return $markets->first(fn (Market $m) => in_array($code, array_map('strtoupper', (array) $m->countries), true));
    }
}
