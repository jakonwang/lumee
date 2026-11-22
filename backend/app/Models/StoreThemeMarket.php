<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/** What one market changes on top of a library theme (see App\Themes\MarketThemes). */
class StoreThemeMarket extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_theme_id', 'market_id', 'draft', 'published', 'published_at'];

    protected function casts(): array
    {
        return ['draft' => 'array', 'published' => 'array', 'published_at' => 'datetime'];
    }

    public function hasUnpublishedChanges(): bool
    {
        return $this->draft !== null && $this->draft != $this->published;
    }
}
