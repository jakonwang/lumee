<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/** 301 redirect from an old storefront path (e.g. after moving from another platform). */
class UrlRedirect extends Model
{
    use BelongsToStore;

    protected $fillable = ['path', 'target'];

    protected function casts(): array
    {
        return ['hits' => 'integer', 'last_hit_at' => 'datetime'];
    }

    /** "/Old-Page/?x=1" → "/old-page"; anything after ? or # dropped, lower-cased, trailing slash trimmed. */
    public static function normalize(string $path): string
    {
        $path = trim($path);
        if (preg_match('~^https?://[^/]+(/.*)?$~i', $path, $m)) {
            $path = $m[1] ?? '/';
        }
        $path = preg_split('/[?#]/', $path)[0];
        $path = '/'.ltrim(rawurldecode($path), '/');

        return mb_strtolower(rtrim($path, '/') ?: '/');
    }
}
