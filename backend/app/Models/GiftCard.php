<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Store credit with a code; spent at checkout like a payment (see App\GiftCards\GiftCards). */
class GiftCard extends Model
{
    use BelongsToStore;

    protected $guarded = ['id', 'store_id'];

    protected $hidden = ['code'];

    protected function casts(): array
    {
        return [
            'initial_value' => 'integer', 'balance' => 'integer', 'expires_on' => 'date',
            'disabled_at' => 'datetime', 'sent_at' => 'datetime',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(GiftCardTransaction::class)->latest('id');
    }

    /** active | used_up | expired | disabled */
    public function status(): string
    {
        return match (true) {
            $this->disabled_at !== null => 'disabled',
            $this->expires_on !== null && $this->expires_on->endOfDay()->isPast() => 'expired',
            $this->balance <= 0 => 'used_up',
            default => 'active',
        };
    }

    public function usable(): bool
    {
        return $this->status() === 'active';
    }

    /** "ABCD EFGH JKLM NPQR" for display to the buyer. */
    public function formattedCode(): string
    {
        return trim(chunk_split($this->code, 4, ' '));
    }

    public static function normalize(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    }
}
