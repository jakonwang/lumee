<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One stock change of a variant (see App\Services\Inventory). */
class InventoryAdjustment extends Model
{
    use BelongsToStore;

    public const UPDATED_AT = null;

    public const REASONS = ['created', 'edit', 'manual', 'order', 'refund', 'cancel', 'import', 'api', 'copy'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['delta' => 'integer', 'quantity_after' => 'integer', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
