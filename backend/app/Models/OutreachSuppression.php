<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/** An address the store must not email again (unsubscribed, bounced, or added by hand). */
class OutreachSuppression extends Model
{
    use BelongsToStore;

    public const UPDATED_AT = null;

    protected $fillable = ['email', 'reason'];

    public static function blocks(string $email): bool
    {
        return static::where('email', mb_strtolower(trim($email)))->exists();
    }

    public static function add(string $email, string $reason): void
    {
        static::firstOrCreate(['email' => mb_strtolower(trim($email))], ['reason' => $reason]);
    }
}
