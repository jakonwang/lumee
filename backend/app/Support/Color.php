<?php

namespace App\Support;

final class Color
{
    public static function isHex(mixed $v): bool
    {
        return is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) === 1;
    }

    /** Mix $hex toward $with by $amount (0..1). */
    public static function mix(string $hex, string $with, float $amount): string
    {
        [$r1, $g1, $b1] = self::rgb($hex);
        [$r2, $g2, $b2] = self::rgb($with);
        $m = fn ($a, $b) => (int) round($a + ($b - $a) * $amount);

        return sprintf('#%02X%02X%02X', $m($r1, $r2), $m($g1, $g2), $m($b1, $b2));
    }

    /** @return array{0:int,1:int,2:int} */
    public static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    /** WCAG relative luminance, used to pick readable text on colored buttons. */
    public static function luminance(string $hex): float
    {
        $c = array_map(function ($v) {
            $v /= 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    /** WCAG contrast ratio between two colors (1–21). */
    public static function contrast(string $a, string $b): float
    {
        [$hi, $lo] = [max(self::luminance($a), self::luminance($b)), min(self::luminance($a), self::luminance($b))];

        return ($hi + 0.05) / ($lo + 0.05);
    }

    /**
     * $hex moved toward black or white (whichever is further from $against) in small steps until the two reach
     * $ratio — WCAG AA is 4.5 for normal text. Unchanged when it already passes, so merchant colors stay as chosen.
     */
    public static function readable(string $hex, string $against, float $ratio = 4.5): string
    {
        if (self::contrast($hex, $against) >= $ratio) {
            return $hex;
        }
        $toward = self::luminance($against) > 0.5 ? '#000000' : '#FFFFFF';
        for ($step = 0.04; $step <= 1.0; $step += 0.04) {
            $c = self::mix($hex, $toward, $step);
            if (self::contrast($c, $against) >= $ratio) {
                return $c;
            }
        }

        return $toward;
    }
}
