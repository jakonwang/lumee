<?php

namespace App\Http\Middleware;

use App\Customers\CustomerSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Storefront account pages: a signed-in customer of THIS store, else the login page. */
class RequireCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! CustomerSession::user()) {
            return redirect()->guest('/account/login');
        }

        return $next($request);
    }
}
