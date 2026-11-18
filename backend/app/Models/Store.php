<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    protected $fillable = [
        'owner_id', 'name', 'handle', 'currency', 'locale', 'timezone',
        'theme', 'status', 'suspended_reason', 'plan', 'trial_ends_at', 'trial_reminded_at', 'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'stripe_account' => 'array',
            'trial_ends_at' => 'datetime',
            'trial_reminded_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role', 'permissions')->withTimestamps();
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    public function shippingRates(): HasMany
    {
        return $this->hasMany(ShippingRate::class);
    }

    public function shippingZones(): HasMany
    {
        return $this->hasMany(ShippingZone::class)->orderBy('position');
    }

    public function subscription(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Subscription::class)->withoutGlobalScope('store');
    }

    public function primaryDomain(): ?Domain
    {
        return $this->domains->firstWhere('is_primary', true) ?? $this->domains->first();
    }

    public function storefrontUrl(): string
    {
        $host = $this->primaryDomain()?->host
            ?? $this->handle.'.'.config('lumee.storefront_domain');
        $port = config('lumee.storefront_port');

        return config('lumee.storefront_scheme').'://'.$host.($port ? ':'.$port : '');
    }
}
