<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Store */
class StoreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'handle' => $this->handle,
            'currency' => $this->currency,
            'locale' => $this->locale,
            'plan' => $this->plan,
            'trial_ends_at' => $this->trial_ends_at,
            'role' => $this->whenPivotLoaded('store_user', fn () => $this->pivot->role),
            // What the signed-in member may access (drives the admin menu; enforced server-side)
            'permissions' => $this->whenPivotLoaded('store_user', fn () => $this->pivot->role === 'owner'
                ? [...array_keys(\App\Tenancy\Permissions::ALL), 'staff']
                : \App\Tenancy\Permissions::clean(json_decode((string) $this->pivot->permissions, true) ?: [])),
            'storefront_url' => $this->storefrontUrl(),
            // Trial / plan status for the admin banner (and the 402 redirect to billing)
            'billing' => app(\App\Billing\Billing::class)->brief($this->resource),
        ];
    }
}
