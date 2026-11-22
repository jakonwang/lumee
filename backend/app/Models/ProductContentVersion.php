<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/** A product's title / description / SEO in one language, saved before it was changed. */
class ProductContentVersion extends Model
{
    use BelongsToStore;

    public const UPDATED_AT = null;

    protected $fillable = ['product_id', 'locale', 'title', 'description', 'seo', 'reason', 'user_id', 'created_at'];

    protected function casts(): array
    {
        return ['seo' => 'array', 'created_at' => 'datetime'];
    }
}
