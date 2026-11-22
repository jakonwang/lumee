<?php

namespace App\Markets;

use Carbon\CarbonImmutable;

/**
 * The big shopping moments per country, with when to start preparing (new products, campaign
 * pages, stock, ads). Dates follow each event's rule (e.g. Black Friday = the day after the 4th
 * Thursday of November); lunar holidays use a table of known dates.
 */
final class ShoppingCalendar
{
    private const EU = ['DE', 'FR', 'IT', 'ES', 'NL', 'BE', 'AT', 'PT', 'IE', 'FI', 'SE', 'DK', 'PL', 'GR', 'CZ', 'HU', 'RO', 'LU'];

    private const SEA = ['ID', 'MY', 'PH', 'TH', 'VN', 'SG'];

    /** Lunar-calendar dates (start of the holiday). */
    private const LUNAR = [
        'ramadan' => ['2026' => '2026-02-18', '2027' => '2027-02-08', '2028' => '2028-01-28'],
        'eid' => ['2026' => '2026-03-20', '2027' => '2027-03-10', '2028' => '2028-02-27'],
        'lunar_new_year' => ['2026' => '2026-02-17', '2027' => '2027-02-06', '2028' => '2028-01-26'],
    ];

    /**
     * @param  string[]  $countries  the market's countries (empty = the default site: worldwide events)
     * @return array<int, array{key: string, name: string, start: string, end: string, prep_from: string, days_left: int, preparing: bool, tip: string}>
     */
    public static function upcoming(array $countries, ?CarbonImmutable $today = null, int $days = 180): array
    {
        $today ??= CarbonImmutable::today();
        $out = [];
        foreach ([$today->year, $today->year + 1] as $year) {
            foreach (self::events($year) as $e) {
                $applies = $countries === [] ? $e['global'] : (bool) array_intersect($countries, $e['countries']);
                [$start, $end] = [$e['start'], $e['start']->addDays($e['length'] - 1)];
                if (! $applies || $end->lt($today) || $start->gt($today->addDays($days))) {
                    continue;
                }
                $prep = $start->subWeeks($e['prep']);
                $out[] = [
                    'key' => $e['key'].'-'.$year, 'name' => $e['name'],
                    'start' => $start->toDateString(), 'end' => $end->toDateString(), 'prep_from' => $prep->toDateString(),
                    'days_left' => (int) max(0, $today->diffInDays($start, false)), 'preparing' => $today->gte($prep) && $today->lte($end),
                    'tip' => $e['tip'],
                ];
            }
        }
        usort($out, fn ($a, $b) => strcmp($a['start'], $b['start']));

        return $out;
    }

    private static function events(int $y): array
    {
        $nth = fn (int $month, int $weekday, int $n) => CarbonImmutable::create($y, $month, 1)->nthOfMonth($n, $weekday);
        $last = fn (int $month, int $weekday) => CarbonImmutable::create($y, $month, 1)->lastOfMonth($weekday);
        $date = fn (string $md) => CarbonImmutable::parse("{$y}-{$md}");
        $blackFriday = $nth(11, CarbonImmutable::THURSDAY, 4)->addDay();
        $lunar = fn (string $k) => isset(self::LUNAR[$k][(string) $y]) ? CarbonImmutable::parse(self::LUNAR[$k][(string) $y]) : null;

        $e = fn (string $key, string $name, ?CarbonImmutable $start, int $length, int $prep, array $countries, bool $global, string $tip) =>
            $start ? compact('key', 'name', 'start', 'length', 'prep', 'countries', 'global', 'tip') : null;

        return array_values(array_filter([
            $e('valentines', '情人节', $date('02-14'), 1, 5, ['US', 'CA', 'GB', 'AU', 'MX', ...self::EU, ...self::SEA], true, '情侣饰品、礼盒、刻字款；提前上礼品分类和礼品包装选项。'),
            $e('lunar_new_year', '春节 / 农历新年', $lunar('lunar_new_year'), 3, 6, ['SG', 'MY', 'VN', 'TH', 'ID', 'PH'], false, '红色、金色系商品，送礼套装；注意春节前后中国工厂和物流停工，提前备货。'),
            $e('ramadan', '斋月', $lunar('ramadan'), 30, 6, ['ID', 'MY', 'AE', 'SA'], false, '斋月期间夜间购物多，适合保守款服饰、礼品和家居；广告集中在晚上。'),
            $e('eid', '开斋节', $lunar('eid'), 3, 6, ['ID', 'MY', 'AE', 'SA'], false, '开斋节是全年最大的送礼和换新季，需要在斋月中段之前到货。'),
            $e('mothers_mx', '墨西哥母亲节', $date('05-10'), 1, 5, ['MX'], false, '墨西哥固定在 5 月 10 日，是全年最重要的送礼节日之一。'),
            $e('mothers_day', '母亲节', $nth(5, CarbonImmutable::SUNDAY, 2), 1, 5, ['US', 'CA', 'AU', 'BR', 'DE', 'IT', 'NL', 'BE'], true, '饰品、刻字、礼盒最好卖；物流截单日要写在首页。'),
            $e('hot_sale', 'Hot Sale（墨西哥）', $last(5, CarbonImmutable::MONDAY), 9, 5, ['MX'], false, '墨西哥官方线上大促，各大电商都参加；准备专门的活动页和折扣。'),
            $e('namorados', '巴西情人节（Dia dos Namorados）', $date('06-12'), 1, 5, ['BR'], false, '巴西的情人节在 6 月 12 日，不是 2 月 14 日。'),
            $e('fathers_day', '父亲节', $nth(6, CarbonImmutable::SUNDAY, 3), 1, 4, ['US', 'CA', 'GB', 'MX'], true, '男士配饰、礼品卡。'),
            $e('back_to_school', '返校季', $date('08-01'), 31, 6, ['US', 'CA'], false, '学生用品、包袋、基础款服饰；7 月就开始比价。'),
            $e('pais_br', '巴西父亲节', $nth(8, CarbonImmutable::SUNDAY, 2), 1, 4, ['BR'], false, '巴西的父亲节在 8 月第二个周日。'),
            $e('9_9', '9.9 大促', $date('09-09'), 1, 4, self::SEA, false, '东南亚平台大促的开始，买家习惯在这天比价。'),
            $e('10_10', '10.10 大促', $date('10-10'), 1, 4, self::SEA, false, '为 11.11 预热，适合测试新品和广告素材。'),
            $e('criancas_br', '巴西儿童节', $date('10-12'), 1, 4, ['BR'], false, '玩具、童装、亲子礼物。'),
            $e('halloween', '万圣节', $date('10-31'), 1, 6, ['US', 'CA', 'GB', 'MX', 'IE'], false, '服装配饰、派对装饰；9 月就开始卖。'),
            $e('singles_day', '双十一（11.11）', $date('11-11'), 1, 6, [...self::SEA, 'CN'], false, '东南亚全年最大的大促之一，提前备货、准备满减。'),
            $e('buen_fin', 'Buen Fin（墨西哥）', $nth(11, CarbonImmutable::MONDAY, 3)->subDays(3), 4, 6, ['MX'], false, '墨西哥的“黑五”，分期付款（MSI）是转化关键。'),
            $e('black_friday', '黑色星期五', $blackFriday, 1, 8, ['US', 'CA', 'GB', 'AU', 'BR', ...self::EU], true, '全年最大流量；提前 2 个月锁库存、准备素材，物流会变慢。'),
            $e('cyber_monday', '网络星期一', $blackFriday->addDays(3), 1, 8, ['US', 'CA', 'GB', ...self::EU], true, '线上专属折扣，和黑五连起来做 4 天活动。'),
            $e('12_12', '12.12 大促', $date('12-12'), 1, 5, self::SEA, false, '年底最后一场大促，礼品和派对用品。'),
            $e('christmas', '圣诞节', $date('12-25'), 1, 8, ['US', 'CA', 'GB', 'AU', 'MX', 'BR', 'PH', ...self::EU], true, '首页写清楚圣诞前最后下单日；跨境物流至少提前 3 周截单。'),
        ]));
    }
}
