<?php

namespace App\Customers;

use App\Models\Customer;
use App\Tenancy\CurrentStore;
use Illuminate\Support\Facades\Auth;

/**
 * The signed-in storefront customer, always checked against the store of THIS request
 * (a session can never act on another store's customers, even if the guard returned one).
 */
class CustomerSession
{
    public static function user(): ?Customer
    {
        $current = app(CurrentStore::class);
        if (! $current->check()) {
            return null;
        }
        /** @var Customer|null $customer */
        $customer = Auth::guard('customer')->user();

        return $customer && $customer->store_id === $current->id() && $customer->hasAccount() ? $customer : null;
    }

    public static function login(Customer $customer): void
    {
        Auth::guard('customer')->login($customer);
        request()->session()->regenerate();
        $customer->forceFill(['last_login_at' => now()])->save();
    }

    public static function logout(): void
    {
        Auth::guard('customer')->logout();
        request()->session()->regenerate();
        request()->session()->regenerateToken();
    }
}
