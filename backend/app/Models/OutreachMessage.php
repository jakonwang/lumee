<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/** An email the sequence sent (or failed to send). */
class OutreachMessage extends Model
{
    use BelongsToStore;

    protected $fillable = ['enrollment_id', 'step', 'subject', 'body', 'message_id', 'status', 'error', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'step' => 'integer'];
    }
}
