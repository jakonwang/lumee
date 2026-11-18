<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A store bought a paid marketplace theme (once; reinstalling is free). Not store-scoped — queries filter explicitly. */
class ThemePurchase extends Model
{
    protected $fillable = ['theme_listing_id', 'store_id', 'user_id', 'platform_invoice_id', 'amount', 'developer_share'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'developer_share' => 'integer'];
    }
}
