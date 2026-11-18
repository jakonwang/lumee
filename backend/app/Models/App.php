<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A third-party app. Not store-scoped: it belongs to the developer's user account. */
class App extends Model
{
    public const STATUSES = ['draft', 'review', 'listed', 'rejected'];

    protected $fillable = ['name', 'tagline', 'description', 'icon_url', 'app_url', 'redirect_uris', 'scopes', 'uninstall_url', 'embedded', 'pricing'];

    protected $hidden = ['client_secret_hash', 'signing_secret'];

    protected function casts(): array
    {
        return ['redirect_uris' => 'array', 'scopes' => 'array', 'embedded' => 'boolean'];
    }

    public function developer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function installations(): HasMany
    {
        return $this->hasMany(AppInstallation::class);
    }

    /** New client secret; returns the plaintext (shown once). */
    public function rotateSecret(): string
    {
        $plain = 'lms_'.Str::random(40);
        $this->forceFill(['client_secret_hash' => hash('sha256', $plain), 'client_secret_prefix' => substr($plain, 0, 10)]);

        return $plain;
    }

    public function secretMatches(string $plain): bool
    {
        return hash_equals((string) $this->client_secret_hash, hash('sha256', $plain));
    }

    public static function uniqueSlug(string $name, ?int $ignore = null): string
    {
        $base = Str::slug($name) ?: 'app';
        $slug = $base;
        $i = 2;
        while (self::where('slug', $slug)->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
