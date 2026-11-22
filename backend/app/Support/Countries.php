<?php

namespace App\Support;

final class Countries
{
    /** Main cross-border destinations. Extend as needed (ISO 3166-1 alpha-2). */
    public static function all(): array
    {
        return [
            'US' => 'United States', 'CA' => 'Canada', 'GB' => 'United Kingdom', 'AU' => 'Australia',
            'NZ' => 'New Zealand', 'DE' => 'Germany', 'FR' => 'France', 'IT' => 'Italy', 'ES' => 'Spain',
            'NL' => 'Netherlands', 'BE' => 'Belgium', 'AT' => 'Austria', 'CH' => 'Switzerland',
            'SE' => 'Sweden', 'DK' => 'Denmark', 'NO' => 'Norway', 'FI' => 'Finland', 'IE' => 'Ireland',
            'PT' => 'Portugal', 'PL' => 'Poland', 'JP' => 'Japan', 'KR' => 'South Korea', 'SG' => 'Singapore',
            'HK' => 'Hong Kong', 'TW' => 'Taiwan', 'MY' => 'Malaysia', 'TH' => 'Thailand', 'PH' => 'Philippines',
            'AE' => 'United Arab Emirates', 'SA' => 'Saudi Arabia', 'IL' => 'Israel', 'MX' => 'Mexico',
            'BR' => 'Brazil', 'CN' => 'China', 'ID' => 'Indonesia', 'VN' => 'Vietnam', 'IN' => 'India',
            'AR' => 'Argentina', 'CL' => 'Chile', 'CO' => 'Colombia', 'PE' => 'Peru',
            'GR' => 'Greece', 'CZ' => 'Czechia', 'HU' => 'Hungary', 'RO' => 'Romania', 'LU' => 'Luxembourg',
        ];
    }
}
