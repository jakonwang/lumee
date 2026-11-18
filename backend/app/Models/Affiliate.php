<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A partner (blogger, influencer, customer) who earns a commission on orders from their link. */
class Affiliate extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'name', 'email', 'code', 'commission_rate', 'status', 'note'];

    protected function casts(): array
    {
        return ['commission_rate' => 'float', 'clicks' => 'integer'];
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(AffiliateCommission::class);
    }
}
