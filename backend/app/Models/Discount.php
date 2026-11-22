<?php

namespace App\Models;

use App\Support\Money;
use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    use BelongsToStore, \App\Markets\InMarketCurrency;

    public const TYPES = ['percentage' => '百分比折扣', 'fixed' => '固定金额', 'free_shipping' => '免运费'];

    protected $fillable = [
        'code', 'type', 'value', 'min_subtotal', 'usage_limit', 'usage_count',
        'once_per_customer', 'active', 'starts_at', 'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'min_subtotal' => 'integer',
            'usage_limit' => 'integer',
            'usage_count' => 'integer',
            'once_per_customer' => 'boolean',
            'active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /** Fixed amounts and the minimum spend are entered in the store currency: in the market currency inside a market. */
    public function getAttribute($key)
    {
        return $this->inMarketCurrency($key, parent::getAttribute($key));
    }

    protected function marketMoneyAttributes(): array
    {
        return ($this->attributes['type'] ?? null) === 'fixed' ? ['value', 'min_subtotal'] : ['min_subtotal'];
    }

    public static function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    /** Amount taken off the subtotal (minor units). Free shipping discounts the shipping instead. */
    public function amountFor(int $subtotal): int
    {
        return match ($this->type) {
            'percentage' => (int) round($subtotal * min(100, $this->value) / 100),
            'fixed' => min($this->value, $subtotal),
            default => 0,
        };
    }

    /** Short shopper-facing description, e.g. "15% off", "$10.00 off", "Free shipping". */
    public function label(string $currency): string
    {
        return match ($this->type) {
            'percentage' => __(':value% off', ['value' => $this->value]),
            'fixed' => __(':amount off', ['amount' => Money::display($this->value, $currency)]),
            default => __('Free shipping'),
        };
    }

    /** "active" | "scheduled" | "expired" | "used_up" | "disabled" */
    public function status(): string
    {
        return match (true) {
            ! $this->active => 'disabled',
            $this->starts_at && $this->starts_at->isFuture() => 'scheduled',
            $this->ends_at && $this->ends_at->isPast() => 'expired',
            $this->usage_limit !== null && $this->usage_count >= $this->usage_limit => 'used_up',
            default => 'active',
        };
    }
}
