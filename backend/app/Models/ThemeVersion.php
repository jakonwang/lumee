<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/** A snapshot of a theme (market_id null) or of one market's layer, taken when it was published. */
class ThemeVersion extends Model
{
    use BelongsToStore;

    public const UPDATED_AT = null;

    protected $fillable = ['store_theme_id', 'market_id', 'config', 'source', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['config' => 'array'];
    }
}
