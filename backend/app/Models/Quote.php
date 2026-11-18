<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A buyer's request for a price; the merchant answers with prices, the buyer accepts → order. */
class Quote extends Model
{
    use BelongsToStore;

    protected $guarded = ['id', 'store_id'];

    protected function casts(): array
    {
        return ['items' => 'array', 'shipping_address' => 'array', 'shipping_price' => 'integer', 'expires_on' => 'date', 'sent_at' => 'datetime', 'number' => 'integer'];
    }

    public function name(): string
    {
        return 'Q'.$this->number;
    }

    public function subtotal(): int
    {
        return (int) collect($this->items)->sum(fn ($i) => (int) ($i['price'] ?? $i['list_price']) * (int) $i['quantity']);
    }

    public function isExpired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->endOfDay()->isPast();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
