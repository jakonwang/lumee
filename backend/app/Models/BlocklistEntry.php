<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/** A store's checkout blocklist: an email, an email domain or an IP address that can't place orders. */
class BlocklistEntry extends Model
{
    use BelongsToStore;

    public const TYPES = ['email', 'domain', 'ip'];

    protected $fillable = ['store_id', 'type', 'value', 'note'];

    protected function casts(): array
    {
        return ['hits' => 'integer'];
    }

    public static function normalize(string $type, string $value): string
    {
        $v = mb_strtolower(trim($value));

        return $type === 'domain' ? ltrim(preg_replace('/^.*@/', '', $v), '.') : $v;
    }
}
