<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'plan', 'preorder', 'gift',
        'store_id', 'product_id', 'variant_id', 'title', 'variant_title',
        'sku', 'image', 'price', 'quantity', 'refunded_quantity', 'total',
    ];

    protected function casts(): array
    {
        return [
            'preorder' => 'boolean', 'gift' => 'array','price' => 'integer', 'quantity' => 'integer', 'refunded_quantity' => 'integer', 'total' => 'integer'];
    }

    public function refundableQuantity(): int
    {
        return max(0, $this->quantity - $this->refunded_quantity);
    }
}
