<?php

namespace App\Catalog;

/**
 * Size conversion for products whose size option uses US sizes (products.size_chart). On the
 * storefront each market sees its own system next to US: shoes in EU / UK / BR / MX (cm),
 * rings in EU (circumference mm) / UK letters, clothing letters in Brazil (PP/P/M/G/GG) and
 * Mexico (CH/M/G/EG). Approximate standard charts — the size guide says so.
 */
final class SizeCharts
{
    public const TYPES = ['shoes_women', 'shoes_men', 'rings', 'clothing'];

    private const EU = ['DE', 'FR', 'IT', 'ES', 'NL', 'BE', 'AT', 'PT', 'IE', 'FI', 'SE', 'DK', 'PL', 'GR', 'CZ', 'HU', 'RO', 'LU', 'CH', 'NO'];

    /** US size => [system => size] */
    private const CHARTS = [
        'shoes_women' => [
            '5' => ['EU' => '35.5', 'UK' => '3', 'BR' => '33', 'MX' => '22'], '5.5' => ['EU' => '36', 'UK' => '3.5', 'BR' => '34', 'MX' => '22.5'],
            '6' => ['EU' => '36.5', 'UK' => '4', 'BR' => '34', 'MX' => '23'], '6.5' => ['EU' => '37', 'UK' => '4.5', 'BR' => '35', 'MX' => '23.5'],
            '7' => ['EU' => '37.5', 'UK' => '5', 'BR' => '35', 'MX' => '24'], '7.5' => ['EU' => '38', 'UK' => '5.5', 'BR' => '36', 'MX' => '24.5'],
            '8' => ['EU' => '38.5', 'UK' => '6', 'BR' => '36', 'MX' => '25'], '8.5' => ['EU' => '39', 'UK' => '6.5', 'BR' => '37', 'MX' => '25.5'],
            '9' => ['EU' => '40', 'UK' => '7', 'BR' => '38', 'MX' => '26'], '9.5' => ['EU' => '40.5', 'UK' => '7.5', 'BR' => '38', 'MX' => '26.5'],
            '10' => ['EU' => '41', 'UK' => '8', 'BR' => '39', 'MX' => '27'], '11' => ['EU' => '42', 'UK' => '9', 'BR' => '40', 'MX' => '28'],
        ],
        'shoes_men' => [
            '7' => ['EU' => '40', 'UK' => '6', 'BR' => '38', 'MX' => '25'], '7.5' => ['EU' => '40.5', 'UK' => '6.5', 'BR' => '38', 'MX' => '25.5'],
            '8' => ['EU' => '41', 'UK' => '7', 'BR' => '39', 'MX' => '26'], '8.5' => ['EU' => '42', 'UK' => '7.5', 'BR' => '40', 'MX' => '26.5'],
            '9' => ['EU' => '42.5', 'UK' => '8', 'BR' => '40', 'MX' => '27'], '9.5' => ['EU' => '43', 'UK' => '8.5', 'BR' => '41', 'MX' => '27.5'],
            '10' => ['EU' => '44', 'UK' => '9', 'BR' => '42', 'MX' => '28'], '10.5' => ['EU' => '44.5', 'UK' => '9.5', 'BR' => '42', 'MX' => '28.5'],
            '11' => ['EU' => '45', 'UK' => '10', 'BR' => '43', 'MX' => '29'], '12' => ['EU' => '46', 'UK' => '11', 'BR' => '44', 'MX' => '30'],
            '13' => ['EU' => '47.5', 'UK' => '12', 'BR' => '45', 'MX' => '31'],
        ],
        'rings' => [
            '4' => ['EU' => '47', 'UK' => 'H'], '5' => ['EU' => '49.5', 'UK' => 'J½'], '6' => ['EU' => '52', 'UK' => 'L½'], '7' => ['EU' => '54.5', 'UK' => 'N½'],
            '8' => ['EU' => '57', 'UK' => 'P½'], '9' => ['EU' => '59.5', 'UK' => 'R½'], '10' => ['EU' => '62', 'UK' => 'T½'], '11' => ['EU' => '64.5', 'UK' => 'V½'],
        ],
        'clothing' => [
            'XS' => ['EU' => '32–34', 'UK' => '4–6', 'BR' => 'PP', 'MX' => 'XCH'], 'S' => ['EU' => '36', 'UK' => '8', 'BR' => 'P', 'MX' => 'CH'],
            'M' => ['EU' => '38–40', 'UK' => '10–12', 'BR' => 'M', 'MX' => 'M'], 'L' => ['EU' => '42', 'UK' => '14', 'BR' => 'G', 'MX' => 'G'],
            'XL' => ['EU' => '44–46', 'UK' => '16–18', 'BR' => 'GG', 'MX' => 'EG'], 'XXL' => ['EU' => '48', 'UK' => '20', 'BR' => 'XGG', 'MX' => 'EEG'],
        ],
    ];

    /** The size system shoppers in these countries use (null = US sizes are fine). */
    public static function systemFor(array $countries): ?string
    {
        $c = strtoupper((string) ($countries[0] ?? ''));

        return match (true) {
            $c === 'GB' => 'UK',
            $c === 'BR' => 'BR',
            $c === 'MX' => 'MX',
            in_array($c, self::EU, true) => 'EU',
            default => null,
        };
    }

    /** "7" in women's shoes for an EU shopper → "37.5". Null when there's no equivalent. */
    public static function convert(string $type, string $usSize, string $system): ?string
    {
        $key = strtoupper(trim(preg_replace('/^(US|SIZE)\s*/i', '', $usSize)));

        return self::CHARTS[$type][$key][$system] ?? self::CHARTS[$type][strtolower($key)][$system] ?? null;
    }

    /**
     * Size guide rows for the product page: the US sizes the product offers, with each system.
     *
     * @return array{systems: string[], rows: array<int, array<string, string>>}|null
     */
    public static function guide(string $type, array $usSizes, ?string $preferred): ?array
    {
        $chart = self::CHARTS[$type] ?? null;
        if (! $chart) {
            return null;
        }
        $systems = array_keys(reset($chart));
        if ($preferred && in_array($preferred, $systems, true)) {
            $systems = [$preferred, ...array_diff($systems, [$preferred])];   // the shopper's system first
        }
        $sizes = array_values(array_filter($usSizes, fn ($s) => isset($chart[strtoupper(trim(preg_replace('/^(US|SIZE)\s*/i', '', $s)))])));
        $sizes = $sizes ?: array_keys($chart);
        $rows = array_map(fn ($s) => ['US' => (string) $s] + array_combine($systems, array_map(fn ($sys) => self::convert($type, (string) $s, $sys) ?? '—', $systems)), $sizes);

        return ['systems' => ['US', ...$systems], 'rows' => $rows];
    }
}
