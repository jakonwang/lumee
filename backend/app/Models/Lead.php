<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A potential B2B buyer found by the lead finder (or added by hand), with its outreach status. */
class Lead extends Model
{
    use BelongsToStore;

    public const STATUSES = ['new', 'contacted', 'replied', 'qualified', 'won', 'lost'];

    protected $fillable = ['lead_search_id', 'source', 'source_id', 'name', 'category', 'segment', 'country', 'city', 'address', 'lat', 'lng', 'phone',
        'website', 'domain', 'emails', 'phones', 'socials', 'summary', 'site_text', 'signals', 'rating', 'reviews_count', 'score', 'score_reasons',
        'ai_scored', 'status', 'starred', 'note', 'enrich_status', 'enrich_error', 'enriched_at', 'contacted_at', 'company_id'];

    protected function casts(): array
    {
        return ['emails' => 'array', 'phones' => 'array', 'socials' => 'array', 'signals' => 'array', 'score_reasons' => 'array', 'ai_scored' => 'boolean',
            'starred' => 'boolean', 'rating' => 'float', 'lat' => 'float', 'lng' => 'float', 'reviews_count' => 'integer', 'score' => 'integer',
            'enriched_at' => 'datetime', 'contacted_at' => 'datetime'];
    }

    public function search(): BelongsTo
    {
        return $this->belongsTo(LeadSearch::class, 'lead_search_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class);
    }
}
