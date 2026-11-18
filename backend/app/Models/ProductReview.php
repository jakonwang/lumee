<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductReview extends Model
{
    use BelongsToStore;

    public const STATUSES = ['pending', 'published', 'hidden'];

    protected $fillable = [
        'product_id', 'customer_id', 'author', 'email', 'rating', 'title', 'body', 'images',
        'status', 'verified', 'source', 'locale', 'reply', 'replied_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'images' => 'array',
            'verified' => 'boolean',
            'replied_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Uploaded photos go with the review (imported ones are external URLs — nothing to delete)
        static::deleted(function (ProductReview $r) {
            foreach ($r->images ?? [] as $img) {
                if (! empty($img['disk']) && ! empty($img['path'])) {
                    Storage::disk($img['disk'])->delete($img['path']);
                }
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scopePublished(Builder $q): Builder
    {
        return $q->where('status', 'published');
    }

    /** @return string[] */
    public function imageUrls(): array
    {
        return array_values(array_filter(array_map(
            fn ($i) => ! empty($i['url']) ? $i['url'] : (! empty($i['path']) ? Storage::disk($i['disk'])->url($i['path']) : null),
            $this->images ?? [],
        )));
    }

    /** "Jane D." — shown on the storefront instead of the full name. */
    public function displayName(): string
    {
        $parts = preg_split('/\s+/u', trim($this->author)) ?: [];
        if (count($parts) < 2) {
            return $this->author;
        }

        return $parts[0].' '.mb_strtoupper(mb_substr(end($parts), 0, 1)).'.';
    }
}
