<?php

namespace App\Research;

use Illuminate\Support\Str;

/**
 * Keyword data exported from other research tools — TikTok Creative Center (hashtags / keywords),
 * Keepa or Helium 10 (Amazon), Google Keyword Planner, Jungle Scout… — pasted or uploaded as CSV.
 * Column names are matched loosely (English and Chinese), so most exports work as they are:
 *   keyword | hashtag | product | title | 关键词 | 标签   → the idea
 *   volume | searches | views | posts | sales | 搜索量 | 播放量 | 销量  → demand
 *   growth | change | trend | 增长 | 环比 (percent; "35%", "+35", "0.35" all read as 35%)
 *   price | avg price | 价格  → typical price (market currency)
 *   competition | sellers | competitors | 竞争 | 卖家数  → number of sellers
 */
final class ExternalImport
{
    private const COLUMNS = [
        'keyword' => ['keyword', 'keywords', 'hashtag', 'hashtags', 'product', 'title', 'search term', 'search terms', 'query', '关键词', '标签', '商品', '标题', '搜索词'],
        'volume' => ['volume', 'search volume', 'searches', 'monthly searches', 'avg. monthly searches', 'views', 'posts', 'videos', 'sales', 'monthly sales', 'units sold', '搜索量', '播放量', '发布量', '销量', '月销量'],
        'growth' => ['growth', 'change', 'trend', 'yoy', 'mom', 'popularity change', '增长', '增长率', '环比', '同比', '趋势'],
        'price' => ['price', 'avg price', 'average price', 'buy box price', 'buy box', '价格', '均价', '平均价格'],
        'sellers' => ['competition', 'competitors', 'sellers', 'number of sellers', 'offers', '竞争', '竞争度', '卖家数', '竞品数'],
    ];

    /**
     * @return array<int, array{keyword: string, volume: ?int, growth: ?float, price: ?int, sellers: ?int}>
     */
    public static function parse(string $csv, int $limit = 500): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', trim($csv)), fn ($l) => trim($l) !== ''));
        if (! $lines) {
            return [];
        }
        $delimiter = substr_count($lines[0], "\t") > substr_count($lines[0], ',') ? "\t" : (substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',');
        $header = array_map(fn ($h) => mb_strtolower(trim((string) $h, " \t\"#")), str_getcsv($lines[0], $delimiter));
        $map = [];
        foreach (self::COLUMNS as $field => $names) {
            foreach ($header as $i => $h) {
                if (! isset($map[$field]) && in_array($h, $names, true)) {
                    $map[$field] = $i;
                }
            }
        }
        // No recognised header: one keyword per line (first column)
        $start = isset($map['keyword']) ? 1 : 0;
        $map['keyword'] ??= 0;

        $rows = [];
        foreach (array_slice($lines, $start) as $line) {
            $cells = str_getcsv($line, $delimiter);
            $keyword = trim((string) ($cells[$map['keyword']] ?? ''), " \t\"#");
            if ($keyword === '' || mb_strlen($keyword) > 120 || isset($rows[mb_strtolower($keyword)])) {
                continue;   // exports are ranked: the first row for a keyword wins
            }
            $num = fn (string $f) => isset($map[$f]) ? self::number((string) ($cells[$map[$f]] ?? '')) : null;
            $growth = $num('growth');
            if ($growth !== null && abs($growth) <= 1 && ! str_contains((string) ($cells[$map['growth']] ?? ''), '%')) {
                $growth *= 100;   // 0.35 = 35%
            }
            $price = $num('price');
            $rows[mb_strtolower($keyword)] = [
                'keyword' => $keyword,
                'volume' => ($v = $num('volume')) !== null ? (int) round($v) : null,
                'growth' => $growth !== null ? round($growth, 1) : null,
                'price' => $price !== null ? (int) round($price * 100) : null,
                'sellers' => ($s = $num('sellers')) !== null ? (int) round($s) : null,
            ];
            if (count($rows) >= $limit) {
                break;
            }
        }

        return array_values($rows);
    }

    /** "1,234", "12.5K", "3M", "35%", "+12", "$19.99", "1.2万" → number */
    private static function number(string $s): ?float
    {
        $s = trim(str_replace([',', ' ', '%', '+', '$', '€', '£', 'R$', '¥'], '', $s));
        if ($s === '' || $s === '-') {
            return null;
        }
        $mult = 1;
        foreach (['k' => 1e3, 'm' => 1e6, 'b' => 1e9, '万' => 1e4, '亿' => 1e8] as $suffix => $m) {
            if (Str::endsWith(mb_strtolower($s), $suffix)) {
                $mult = $m;
                $s = mb_substr($s, 0, -mb_strlen($suffix));
                break;
            }
        }

        return is_numeric($s) ? (float) $s * $mult : null;
    }
}
