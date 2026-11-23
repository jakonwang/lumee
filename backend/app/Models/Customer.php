<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A store's customer. Guest checkout creates the row; a password makes it a storefront account
 * (auth guard "customer"). Rows are per store: the same email at two stores is two customers.
 */
class Customer extends Authenticatable
{
    use BelongsToStore;

    protected $fillable = [
        'email', 'first_name', 'last_name', 'phone', 'orders_count', 'total_spent',
        'accepts_marketing', 'marketing_source', 'marketing_updated_at', 'default_address',
    ];

    protected $hidden = ['password'];

    /** No "remember me" column — sessions only. */
    protected $rememberTokenName = '';

    protected function casts(): array
    {
        return [
            'total_spent' => 'integer',
            'orders_count' => 'integer',
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'marketing_updated_at' => 'datetime',
            'last_login_at' => 'datetime',
            'accepts_marketing' => 'boolean',
            'default_address' => 'array',
            'tags' => 'array',
            'points' => 'integer',
            'birthday' => 'date',
            'last_viewed_at' => 'datetime',
            'tax_exempt' => 'boolean',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function hasAccount(): bool
    {
        return $this->password !== null || $this->google_id !== null;
    }

    public function isVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    protected static function booted(): void
    {
        // Newly subscribed → welcome flow (if the store has it switched on)
        static::created(fn (Customer $c) => app(\App\Webhooks\Webhooks::class)->customerCreated($c));
        static::saved(function (Customer $c) {
            if ($c->wasChanged('accepts_marketing') && $c->accepts_marketing || ($c->wasRecentlyCreated && $c->accepts_marketing)) {
                app(\App\Marketing\Flows::class)->onSubscribed($c);
            }
        });
    }

    /** Record a marketing consent change (subscribe or unsubscribe) and where it came from. */
    public function setMarketingConsent(bool $subscribed, string $source): void
    {
        if ((bool) $this->accepts_marketing === $subscribed && $this->exists) {
            return;
        }
        $this->forceFill(['accepts_marketing' => $subscribed, 'marketing_source' => $source, 'marketing_updated_at' => now()]);
    }
}
