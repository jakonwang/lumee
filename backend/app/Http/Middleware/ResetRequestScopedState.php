<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global, runs first: clears per-request singletons (current store, cart, theme) so nothing
 * from a previous request can leak into this one when the app instance is reused
 * (long-running workers, test suites). Must run before controllers are instantiated,
 * which is why it is global rather than route middleware.
 */
class ResetRequestScopedState
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->forgetScopedInstances();
        auth()->forgetGuards();   // a signed-in admin/customer from a previous request must not carry over

        return $next($request);
    }
}
