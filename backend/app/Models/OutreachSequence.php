<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A first email and follow-ups. See App\Outreach\Sequencer. */
class OutreachSequence extends Model
{
    use BelongsToStore;

    protected $fillable = ['name', 'audience', 'steps', 'ai_first', 'auto', 'status'];

    protected function casts(): array
    {
        return ['steps' => 'array', 'auto' => 'array', 'ai_first' => 'boolean'];
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(OutreachEnrollment::class, 'sequence_id');
    }
}
