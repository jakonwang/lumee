<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/** A product idea for a market, with its evidence and score (App\Research). */
class ProductOpportunity extends Model
{
    use BelongsToStore;

    public const STATUSES = ['new', 'watching', 'sourcing', 'testing', 'won', 'dropped'];

    protected $fillable = ['market_id', 'keyword', 'source', 'status', 'signals', 'scores', 'score', 'ai', 'note', 'enriched_at', 'analyzed_at'];

    protected function casts(): array
    {
        return ['signals' => 'array', 'scores' => 'array', 'ai' => 'array', 'market_id' => 'integer', 'score' => 'integer',
            'enriched_at' => 'datetime', 'analyzed_at' => 'datetime'];
    }
}
