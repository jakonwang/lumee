<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An Instagram / TikTok / YouTube creator to collaborate with. See App\Marketing\Creators. */
class Creator extends Model
{
    use BelongsToStore;

    public const STATUSES = ['new', 'contacted', 'replied', 'agreed', 'declined'];

    protected $fillable = ['platform', 'handle', 'url', 'name', 'country', 'niche', 'followers', 'emails', 'whatsapp', 'website', 'bio',
        'score', 'score_reasons', 'status', 'affiliate_id', 'note', 'contacted_at'];

    protected function casts(): array
    {
        return ['emails' => 'array', 'score_reasons' => 'array', 'followers' => 'integer', 'score' => 'integer', 'contacted_at' => 'datetime'];
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }
}
