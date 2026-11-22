<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A supplier / factory, with a private portal link to confirm purchase orders and offer new products. */
class Supplier extends Model
{
    use BelongsToStore;

    protected $fillable = ['name', 'contact_name', 'email', 'phone', 'platform', 'note', 'token'];

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
