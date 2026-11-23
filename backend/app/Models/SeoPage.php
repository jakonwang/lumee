<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A landing page for one market and search keyword. See App\Marketing\SeoPages. */
class SeoPage extends Model
{
    use BelongsToStore;

    protected $fillable = ['market_id', 'locale', 'keyword', 'slug', 'title', 'meta_description', 'heading', 'intro', 'faq', 'product_ids', 'status', 'ai', 'opportunity_id', 'published_at'];

    protected function casts(): array
    {
        return ['faq' => 'array', 'product_ids' => 'array', 'ai' => 'boolean', 'published_at' => 'datetime'];
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }
}
