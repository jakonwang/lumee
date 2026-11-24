<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One lead-finder run: what was searched, where, and how far it got. */
class LeadSearch extends Model
{
    use BelongsToStore;

    protected $fillable = ['name', 'industry', 'keywords', 'country', 'cities', 'source', 'channels', 'repeat_daily', 'repeated_at', 'status', 'stage', 'found', 'new_leads', 'enriched', 'to_enrich',
        'error', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['keywords' => 'array', 'cities' => 'array', 'channels' => 'array', 'repeat_daily' => 'boolean', 'repeated_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }
}
