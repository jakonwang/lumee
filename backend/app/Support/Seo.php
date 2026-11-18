<?php

namespace App\Support;

use Illuminate\Support\Str;

/** Search-engine listing helpers: cleaning merchant input and building page meta. */
final class Seo
{
    /** {title, description} with whitespace collapsed; null when both are empty. */
    public static function clean(?array $seo): ?array
    {
        $title = self::line($seo['title'] ?? null, 70);
        $description = self::line($seo['description'] ?? null, 320);

        return $title === null && $description === null ? null : ['title' => $title, 'description' => $description];
    }

    /** Plain one-line text from HTML/Markdown-ish content, cut at a word boundary. */
    public static function text(?string $content, int $limit = 160): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $content), ENT_QUOTES | ENT_HTML5)));

        return Str::limit($text, $limit);
    }

    private static function line(mixed $v, int $max): ?string
    {
        $v = trim(preg_replace('/\s+/u', ' ', (string) $v));

        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
