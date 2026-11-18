<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A merchant's rating (1–5) and review of an app, one per store and app. Not store-scoped:
 * every merchant reads every listed app's reviews. Writes check the store explicitly.
 */
class AppReview extends Model
{
    protected $fillable = ['app_id', 'store_id', 'user_id', 'rating', 'body'];

    protected function casts(): array
    {
        return ['rating' => 'integer'];
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** Keep apps.rating_avg / rating_count in step. */
    public static function refreshRating(int $appId): void
    {
        $q = self::where('app_id', $appId);
        App::whereKey($appId)->update(['rating_avg' => round((float) $q->avg('rating'), 2), 'rating_count' => $q->count()]);
    }

    protected static function booted(): void
    {
        static::saved(fn (AppReview $r) => self::refreshRating($r->app_id));
        static::deleted(fn (AppReview $r) => self::refreshRating($r->app_id));
    }
}
