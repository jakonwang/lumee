<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    use BelongsToStore;

    protected $fillable = ['cart_id', 'variant_id', 'quantity', 'plan'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /** Variant's own image if set, else the product's main image. */
    public function imageUrl(): ?string
    {
        return $this->variant->image?->url() ?? $this->variant->product->featured_image;
    }

    /** Price for one unit: subscription items get the product's subscribe-and-save discount. */
    public function unitPrice(): int
    {
        $price = app(\App\Pricing\PriceLists::class)->unitPrice((int) $this->variant_id, (int) $this->quantity, (int) $this->variant->price);

        return \App\Subscriptions\Plans::price($this->variant->product, $price, $this->plan);
    }

    public function planLabel(): string
    {
        return \App\Subscriptions\Plans::label($this->plan);
    }

    public function lineTotal(): int
    {
        return $this->unitPrice() * $this->quantity;
    }
}
