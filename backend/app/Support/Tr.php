<?php

namespace App\Support;

/**
 * Translate labels that live in constants / config (theme schema, plans, permissions…), which
 * can't call __() themselves. Applied when sending them to the admin, in the admin's language.
 */
final class Tr
{
    /** Keys whose string values are UI text. */
    private const TEXT_KEYS = ['label', 'description', 'tagline', 'name', 'title', 'hint'];

    /** Translate every string of a flat map ['key' => '中文'] or list. */
    public static function map(array $values): array
    {
        return array_map(fn ($v) => is_string($v) ? __($v) : $v, $values);
    }

    /** Walk a nested schema: translate text keys, "options" labels and "features" lists. */
    public static function deep(mixed $value, ?string $key = null): mixed
    {
        if (is_string($value)) {
            return $key !== null && in_array($key, self::TEXT_KEYS, true) ? __($value) : $value;
        }
        if (! is_array($value)) {
            return $value;
        }
        if (in_array($key, ['options', 'features'], true)) {
            return self::map($value);
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::deep($v, is_string($k) ? $k : $key);
        }

        return $out;
    }
}
