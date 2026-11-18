<?php

namespace App\Http\Middleware;

use App\Tenancy\CurrentStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard: "perm:orders" or any-of "perm:products,online_store"; "perm:owner" = store owner only.
 * Runs after ResolveAdminStore (which loads the membership).
 */
class RequirePermission
{
    public function __construct(private CurrentStore $current) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $allowed = in_array('owner', $permissions, true)
            ? $this->current->isOwner()
            : collect($permissions)->contains(fn ($p) => $this->current->can($p));

        if (! $allowed) {
            return response()->json(['message' => __('你没有权限执行此操作，请联系店铺所有者')], 403);
        }

        return $next($request);
    }
}
