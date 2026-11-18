<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StoreInvitation extends Model
{
    use BelongsToStore;

    public const VALID_DAYS = 7;

    protected $fillable = ['store_id', 'email', 'permissions', 'token_hash', 'invited_by', 'expires_at', 'accepted_at'];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'expires_at' => 'datetime', 'accepted_at' => 'datetime'];
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** Issue a fresh secret token. Only its hash is stored; returns the plain token for the email link. */
    public function issueToken(): string
    {
        $token = Str::random(48);
        $this->token_hash = hash('sha256', $token);
        $this->expires_at = now()->addDays(self::VALID_DAYS);

        return $token;
    }

    /** Look up by plain token across all stores (the invitee has no store context yet). */
    public static function findByToken(string $token): ?self
    {
        return static::withoutGlobalScope('store')->with('store', 'inviter')
            ->where('token_hash', hash('sha256', $token))->first();
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }
}
