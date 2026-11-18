<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A theme in the marketplace: a Lumée theme package uploaded by a developer. Not store-scoped. */
class ThemeListing extends Model
{
    public const STATUSES = ['draft', 'review', 'listed', 'rejected'];

    protected $fillable = ['name', 'tagline', 'description', 'preview_url', 'demo_url'];

    protected function casts(): array
    {
        return ['swatches' => 'array', 'installs' => 'integer', 'version' => 'integer'];
    }

    public function developer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public static function uniqueSlug(string $name, ?int $ignore = null): string
    {
        $base = Str::slug($name) ?: 'theme';
        $slug = $base;
        $i = 2;
        while (self::where('slug', $slug)->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
