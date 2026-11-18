<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A monthly payout of a developer's marketplace earnings (USD cents). The payout account is a snapshot, encrypted. */
class PartnerPayout extends Model
{
    protected $fillable = ['user_id', 'period', 'amount', 'status', 'method', 'details', 'reference', 'paid_at'];

    protected $hidden = ['details'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'paid_at' => 'datetime', 'details' => 'encrypted'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
