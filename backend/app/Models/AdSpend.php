<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/** What ads cost on a day, per platform, market and campaign. See App\Analytics\AdReport. */
class AdSpend extends Model
{
    use BelongsToStore;

    public const PLATFORMS = ['google', 'meta', 'tiktok', 'bing', 'pinterest', 'snapchat', 'other'];

    protected $table = 'ad_spend';

    protected $fillable = ['date', 'platform', 'market_id', 'campaign', 'spend', 'currency', 'amount', 'impressions', 'clicks', 'source'];

    protected function casts(): array
    {
        return ['spend' => 'integer', 'amount' => 'integer', 'impressions' => 'integer', 'clicks' => 'integer'];
    }
}
