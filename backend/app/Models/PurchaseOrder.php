<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A purchase order to a supplier (App\Supply\Purchasing). Costs in the store currency. */
class PurchaseOrder extends Model
{
    use BelongsToStore;

    public const STATUSES = ['draft', 'sent', 'confirmed', 'shipped', 'received', 'cancelled'];

    protected $fillable = ['supplier_id', 'number', 'status', 'items', 'total', 'note', 'supplier_note', 'expected_at', 'tracking',
        'sent_at', 'confirmed_at', 'shipped_at', 'received_at'];

    protected function casts(): array
    {
        return ['items' => 'array', 'total' => 'integer', 'number' => 'integer', 'expected_at' => 'date',
            'sent_at' => 'datetime', 'confirmed_at' => 'datetime', 'shipped_at' => 'datetime', 'received_at' => 'datetime'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function name(): string
    {
        return 'PO-'.$this->number;
    }
}
