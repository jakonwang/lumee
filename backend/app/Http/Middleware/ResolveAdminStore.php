<?php

namespace App\Http\Middleware;

use App\Tenancy\CurrentStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin API: picks the store from the X-Store-Id header (falls back to the user's first store)
 * and verifies the authenticated user is a member of it.
 */
class ResolveAdminStore
{
    public function __construct(private CurrentStore $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $storeId = $request->header('X-Store-Id');

        $query = $user->stores()->with('domains');
        $store = $storeId ? $query->whereKey($storeId)->first() : $query->first();

        if (! $store) {
            return response()->json(['message' => __('无权访问该店铺')], 403);
        }

        $this->current->set($store);
        // Membership is re-read on every request, so removing a staff member takes effect immediately
        $this->current->setMembership(
            $store->pivot->role,
            json_decode((string) ($store->pivot->permissions ?? '[]'), true) ?: [],
        );

        return $next($request);
    }
}
