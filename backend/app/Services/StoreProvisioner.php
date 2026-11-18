<?php

namespace App\Services;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StoreProvisioner
{
    /** Create a store with its owner membership and free platform subdomain. */
    public function create(User $owner, string $name, array $attributes = []): Store
    {
        return DB::transaction(function () use ($owner, $name, $attributes) {
            $store = Store::create([
                'owner_id' => $owner->id,
                'name' => $name,
                'handle' => $this->uniqueHandle($name),
                'theme' => config('lumee.default_theme'),
                'trial_ends_at' => now()->addDays(config('lumee.trial_days')),
                ...$attributes,
            ]);

            $store->users()->attach($owner->id, ['role' => 'owner']);

            $store->domains()->create([
                'host' => $store->handle.'.'.config('lumee.storefront_domain'),
                'type' => 'platform',
                'is_primary' => true,
                'verified_at' => now(),
            ]);

            // Sensible default shipping so a new store can take orders immediately
            // One worldwide zone; the merchant can add country-specific zones later
            $zone = $store->shippingZones()->create(['name' => __('全球'), 'countries' => null, 'position' => 0]);
            $zone->rates()->createMany(array_map(fn ($r) => $r + ['store_id' => $store->id], [
                ['name' => 'Standard Shipping', 'description' => '7–12 business days', 'price' => 990, 'position' => 0],
                ['name' => 'Free Standard Shipping', 'description' => '7–12 business days', 'price' => 0, 'min_subtotal' => 9900, 'position' => 1],
                ['name' => 'Express Shipping', 'description' => '3–5 business days', 'price' => 2490, 'position' => 2],
            ]));

            return $store->load('domains');
        });
    }

    private function uniqueHandle(string $name): string
    {
        $base = Str::slug($name) ?: 'store';
        $base = Str::limit($base, 40, '');
        $reserved = ['www', 'admin', 'api', 'ops', 'app', 'mail', 'cdn', 'static', 'help', 'status'];

        $handle = $base;
        while (in_array($handle, $reserved, true) || Store::where('handle', $handle)->exists()) {
            $handle = $base.'-'.Str::lower(Str::random(4));
        }

        return $handle;
    }
}
