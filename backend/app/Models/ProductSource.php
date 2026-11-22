<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/** The page a catalog product was collected from. */
class ProductSource extends Model
{
    use BelongsToStore;

    protected $fillable = ['product_id', 'url', 'url_hash', 'platform', 'currency'];
}
