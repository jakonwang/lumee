<?php

namespace App\Http\Middleware;

use App\Billing\Billing;
use App\Tenancy\CurrentStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Storefront of a paused store (trial over, plan lapsed, suspended): a "temporarily closed"
 * page instead of the shop. Buyers can still open their existing order status pages.
 */
class StorefrontOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        $current = app(CurrentStore::class);
        if ($current->check() && ! $request->routeIs('store.order', 'l.store.order', 'store.robots')
            && ! app(Billing::class)->isOpen($current->getOrFail())) {
            return response()->view('platform.store-closed', ['store' => $current->getOrFail()], 503)
                ->header('Retry-After', '86400')
                ->header('X-Robots-Tag', 'noindex');
        }

        return $next($request);
    }
}
