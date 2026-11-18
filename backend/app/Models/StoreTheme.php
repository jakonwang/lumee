<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class StoreTheme extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'theme', 'name', 'source', 'draft', 'published', 'published_at'];

    protected function casts(): array
    {
        return [
            'draft' => 'array',
            'published' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function hasUnpublishedChanges(): bool
    {
        return $this->draft !== null && $this->draft != $this->published;
    }
}
