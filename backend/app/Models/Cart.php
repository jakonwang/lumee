<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends Model
{
    use BelongsToStore;

    protected $fillable = ['token', 'discount_code', 'gift_card_code'];

    protected function casts(): array
    {
        return ['checkout_started_at' => 'datetime', 'recovery_sent_at' => 'datetime'];
    }

    /** Remember who reached checkout (for the abandoned checkout email) and in which language. */
    public function rememberContact(?string $email): void
    {
        $this->forceFill([
            'email' => $email ? mb_strtolower(trim($email)) : $this->email,
            'locale' => app(\App\I18n\Localization::class)->locale(),
            'checkout_started_at' => $this->checkout_started_at ?? now(),
        ])->save();
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->oldest('id');
    }

    public function itemCount(): int
    {
        return (int) $this->items->sum('quantity');
    }

    /** The applied discount, if its code still exists (validity is re-checked at checkout). */
    public function discount(): ?Discount
    {
        return $this->discount_code ? Discount::where('code', $this->discount_code)->first() : null;
    }

    /** The applied gift card while it can still be used (in the store's currency), else null. */
    public function giftCard(): ?GiftCard
    {
        if (! $this->gift_card_code) {
            return null;
        }
        $currency = app(\App\Tenancy\CurrentStore::class)->get()?->currency;

        return $currency ? app(\App\GiftCards\GiftCards::class)->findUsable($this->gift_card_code, $currency) : null;
    }

    public function hasSubscription(): bool
    {
        return $this->items->contains(fn ($i) => $i->plan);
    }

    /** Total weight in grams (for weight-based shipping rates). */
    public function weight(): int
    {
        return (int) $this->items->sum(fn (CartItem $i) => (int) $i->variant?->weight * $i->quantity);
    }

    /** Subtotal at current variant prices (minor units). */
    public function subtotal(): int
    {
        return (int) $this->items->sum(fn (CartItem $i) => $i->lineTotal());
    }
}
