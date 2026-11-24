<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One recipient going through a sequence. */
class OutreachEnrollment extends Model
{
    use BelongsToStore;

    public const STATUSES = ['active', 'replied', 'completed', 'unsubscribed', 'bounced', 'stopped', 'failed'];

    protected $fillable = ['sequence_id', 'lead_id', 'creator_id', 'email', 'name', 'country', 'step', 'status', 'next_at', 'last_sent_at', 'first', 'token', 'failures', 'error'];

    protected function casts(): array
    {
        return ['first' => 'array', 'next_at' => 'datetime', 'last_sent_at' => 'datetime', 'step' => 'integer', 'failures' => 'integer'];
    }

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(OutreachSequence::class, 'sequence_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Creator::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(OutreachMessage::class, 'enrollment_id');
    }
}
