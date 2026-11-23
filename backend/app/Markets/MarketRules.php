<?php

namespace App\Markets;

use Carbon\CarbonImmutable;

/**
 * Plain rules for "does this product suit this country", used with or without AI:
 *  - plug standard written in the product (US / UK / EU / AU plug) vs the country's sockets
 *  - season: summer or winter goods vs the country's hemisphere and the month (tropical and Gulf
 *    countries have no winter)
 *  - goods that are banned or not sold online there (alcohol and pork in the Gulf, e-cigarettes)
 *  - local festivals (Ramadan, Thanksgiving, Diwali, Día de Muertos…)
 *
 * Each hit moves the score up or down and says why; a "hard" hit means it shouldn't be sold there.
 */
class MarketRules
{
    /** Which plug a country uses (the one a product would be labelled with). */
    private const PLUGS = [
        'us' => ['US', 'CA', 'MX', 'JP', 'TW', 'PH', 'CO', 'EC', 'PE', 'VE', 'TH'],
        'uk' => ['GB', 'IE', 'AE', 'SA', 'QA', 'KW', 'BH', 'OM', 'MY', 'SG', 'HK', 'CY', 'MT'],
        'eu' => ['DE', 'FR', 'IT', 'ES', 'NL', 'BE', 'AT', 'PT', 'FI', 'SE', 'DK', 'PL', 'GR', 'CZ', 'HU', 'RO', 'LU', 'NO', 'CH', 'TR', 'RU', 'UA', 'KR', 'ID', 'VN', 'BR', 'CL', 'EG', 'MA'],
        'au' => ['AU', 'NZ', 'AR', 'CN', 'FJ'],
    ];

    private const PLUG_WORDS = [
        'us' => '/\b(us|usa|american)[\s-]?(plug|standard)\b|美规|美标插头/iu',
        'uk' => '/\b(uk|british)[\s-]?(plug|standard)\b|英规|英标插头/iu',
        'eu' => '/\b(eu|euro|european)[\s-]?(plug|standard)\b|欧规|欧标插头/iu',
        'au' => '/\b(au|aus|australian)[\s-]?(plug|standard)\b|澳规|澳标插头/iu',
    ];

    private const SOUTH = ['AU', 'NZ', 'BR', 'AR', 'CL', 'UY', 'PY', 'ZA'];

    /** No real winter: winter clothing doesn't sell there. */
    private const HOT = ['SG', 'MY', 'TH', 'ID', 'PH', 'VN', 'AE', 'SA', 'QA', 'KW', 'BH', 'OM', 'EG', 'IN', 'CO', 'VE', 'NG', 'KE'];

    private const SUMMER = '/\b(swim\s?suit|swimwear|bikini|beach|sandals?|flip[\s-]?flops?|sun\s?hat|summer|pool\s?float)\b|泳衣|比基尼|沙滩|凉鞋|夏季|夏装|traje de baño|biquíni|verano|verão/iu';

    private const WINTER = '/\b(winter|snow|down jacket|puffer|thermal|fleece[\s-]lined|ski|beanie|earmuffs?|hand warmer)\b|冬季|冬装|羽绒|保暖|加绒|invierno|inverno/iu';

    private const GULF_STRICT = ['SA', 'KW', 'QA'];

    private const ALCOHOL = '/\b(alcohol|beer|wine bottle|liquor|vodka|whisk(e)?y|rum|gin|tequila|sake)\b|白酒|啤酒|红酒|酒精饮料/iu';

    private const PORK = '/\b(pork|bacon|ham|lard)\b|猪肉|培根|火腿/iu';

    /** E-cigarettes are banned or can't be sold online there. */
    private const VAPE_BANNED = ['SG', 'TH', 'BR', 'MX', 'AR', 'IN', 'QA', 'KW', 'JP', 'AU', 'TR', 'VN'];

    private const VAPE = '/\b(e[\s-]?cig(arette)?s?|vapes?|vaping|vape pen|e[\s-]?liquid|pod system)\b|电子烟|烟油/iu';

    /** Festival goods: [pattern, countries where it's a selling point, label]. */
    private const FESTIVALS = [
        ['/\b(ramadan|eid|iftar|hijab|abaya|prayer mat|kaftan)\b|斋月|开斋|头巾|礼拜毯/iu', ['AE', 'SA', 'QA', 'KW', 'BH', 'OM', 'EG', 'MA', 'TR', 'ID', 'MY'], '斋月 / 开斋节'],
        ['/\bthanksgiving\b|感恩节/iu', ['US', 'CA'], '感恩节'],
        ['/\b(diwali|rakhi|holi)\b|排灯节/iu', ['IN'], '排灯节等印度节日'],
        ['/d[íi]a de (los )?muertos|day of the dead|calavera|亡灵节/iu', ['MX'], '亡灵节'],
        ['/\b(halloween)\b|万圣节/iu', ['US', 'CA', 'GB', 'IE', 'MX'], '万圣节'],
        ['/\b(4th of july|fourth of july|independence day)\b|美国独立日/iu', ['US'], '美国独立日'],
        ['/\b(lunar new year|chinese new year)\b|春节|新年红包/iu', ['SG', 'MY', 'HK', 'TW', 'VN', 'ID'], '农历新年'],
    ];

    /**
     * @param  string[]  $countries  the market's countries (upper case)
     * @return array<int, array{delta: float, reason: string, hard: bool}>
     */
    public static function check(string $text, array $countries, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $countries = array_map('strtoupper', $countries);
        if (! $countries) {
            return [];
        }
        $in = fn (array $list) => (bool) array_intersect($countries, $list);
        $hits = [];

        // Plugs: the product names a standard; the market's main country uses another one
        $named = array_keys(array_filter(self::PLUG_WORDS, fn ($re) => preg_match($re, $text)));
        if ($named) {
            $local = collect(self::PLUGS)->filter(fn ($list) => in_array($countries[0], $list, true))->keys()->all();
            if (array_intersect($named, $local)) {
                $hits[] = ['delta' => 0.2, 'reason' => '插头规格适合当地', 'hard' => false];
            } elseif ($local) {
                $hits[] = ['delta' => -0.5, 'reason' => '插头规格（'.strtoupper(implode('/', $named)).'）和当地插座不一样', 'hard' => true];
            }
        }

        // Seasons
        $month = $today->month;
        $south = $in(self::SOUTH);
        $summerNow = $south ? in_array($month, [11, 12, 1, 2], true) : in_array($month, [5, 6, 7, 8], true);
        $winterNow = $south ? in_array($month, [5, 6, 7, 8], true) : in_array($month, [11, 12, 1, 2], true);
        if (preg_match(self::WINTER, $text)) {
            if ($in(self::HOT)) {
                $hits[] = ['delta' => -0.35, 'reason' => '冬季用品，当地气候炎热', 'hard' => false];
            } elseif ($winterNow) {
                $hits[] = ['delta' => 0.25, 'reason' => '当地正值冬季', 'hard' => false];
            } elseif ($summerNow) {
                $hits[] = ['delta' => -0.25, 'reason' => '当地正值夏季，冬季用品不当季', 'hard' => false];
            }
        }
        if (preg_match(self::SUMMER, $text)) {
            if ($in(self::HOT) || $summerNow) {
                $hits[] = ['delta' => 0.25, 'reason' => $summerNow ? '当地正值夏季' : '当地气候炎热，夏季用品常年好卖', 'hard' => false];
            } elseif ($winterNow) {
                $hits[] = ['delta' => -0.25, 'reason' => '当地正值冬季，夏季用品不当季', 'hard' => false];
            }
        }

        // Restricted goods
        if ($in(self::GULF_STRICT) && preg_match(self::ALCOHOL, $text)) {
            $hits[] = ['delta' => -0.5, 'reason' => '当地禁止销售酒类', 'hard' => true];
        }
        if ($in(self::GULF_STRICT) && preg_match(self::PORK, $text)) {
            $hits[] = ['delta' => -0.5, 'reason' => '当地禁止销售猪肉制品', 'hard' => true];
        }
        if ($in(self::VAPE_BANNED) && preg_match(self::VAPE, $text)) {
            $hits[] = ['delta' => -0.5, 'reason' => '当地禁止或限制网上销售电子烟', 'hard' => true];
        }

        // Festivals
        foreach (self::FESTIVALS as [$re, $where, $label]) {
            if (preg_match($re, $text)) {
                $hits[] = $in($where)
                    ? ['delta' => 0.25, 'reason' => "{$label}相关商品，当地有这个节日", 'hard' => false]
                    : ['delta' => -0.2, 'reason' => "{$label}相关商品，当地不过这个节日", 'hard' => false];
            }
        }

        return $hits;
    }
}
