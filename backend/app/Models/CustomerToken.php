<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** One-time email link (verify email / reset password). Only a hash of the token is stored. */
class CustomerToken extends Model
{
    use BelongsToStore;

    public const UPDATED_AT = null;

    protected $fillable = ['store_id', 'customer_id', 'type', 'token_hash', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Create a token (replacing older ones of the same type) and return the plain value for the link. */
    public static function issue(Customer $customer, string $type, int $minutes): string
    {
        static::where('customer_id', $customer->id)->where('type', $type)->delete();
        $plain = Str::random(48);
        static::create([
            'store_id' => $customer->store_id,
            'customer_id' => $customer->id,
            'type' => $type,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addMinutes($minutes),
        ]);

        return $plain;
    }

    /** Valid, unexpired token of this type in the current store, or null. */
    public static function findValid(string $plain, string $type): ?self
    {
        return static::with('customer')
            ->where('token_hash', hash('sha256', $plain))
            ->where('type', $type)
            ->where('expires_at', '>', now())
            ->first();
    }
}
