<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Promotion extends Model
{
    use BelongsToStore;

    public const TYPES = ['sale' => '限时折扣', 'tiered' => '满减', 'bundle' => '捆绑优惠'];

    protected $fillable = ['title', 'type', 'value_type', 'config', 'combines_with_codes', 'enabled', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'combines_with_codes' => 'boolean',
            'enabled' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'orders_count' => 'integer',
            'discount_total' => 'integer',
        ];
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('enabled', true)
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    /** active | scheduled | ended | disabled */
    public function status(): string
    {
        return match (true) {
            ! $this->enabled => 'disabled',
            $this->starts_at && $this->starts_at->isFuture() => 'scheduled',
            $this->ends_at && $this->ends_at->isPast() => 'ended',
            default => 'active',
        };
    }

    /** Apply this promotion's value to an amount (minor units). Fixed values are per unit / per set. */
    public function reduce(int $amount, int $times = 1): int
    {
        $value = (float) ($this->config['value'] ?? 0);
        $off = $this->value_type === 'percentage'
            ? (int) round($amount * min(100, max(0, $value)) / 100)
            : app(\App\Markets\MarketContext::class)->money((int) round($value * 100)) * $times;   // fixed values are in the store currency

        return min($amount, max(0, $off));
    }
}
