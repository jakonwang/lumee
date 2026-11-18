<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Ops console API: Lumée staff only (users.is_platform_admin, set with `php artisan lumee:platform-admin`). */
class RequirePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->is_platform_admin) {
            return response()->json(['message' => __('没有平台运营权限')], 403);
        }

        return $next($request);
    }
}
