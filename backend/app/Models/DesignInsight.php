<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/** One week's look at a market's numbers: findings and design suggestions (App\Themes\DesignInsights). */
class DesignInsight extends Model
{
    use BelongsToStore;

    protected $guarded = ['id', 'store_id'];

    protected function casts(): array
    {
        return ['week' => 'date', 'metrics' => 'array', 'findings' => 'array', 'suggestions' => 'array'];
    }
}
