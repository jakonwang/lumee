<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/** A customer's request to export or erase their data (from their account, or logged by the merchant). */
class PrivacyRequest extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'customer_id', 'email', 'type', 'source', 'status', 'note', 'completed_at'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }
}
