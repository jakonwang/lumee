<?php

namespace App\Http\Middleware;

use App\Tenancy\CurrentStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** 404 for storefront-only routes when the host doesn't belong to an active store. */
class RequireStorefrontStore
{
    public function __construct(private CurrentStore $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->current->check(), 404);

        return $next($request);
    }
}
