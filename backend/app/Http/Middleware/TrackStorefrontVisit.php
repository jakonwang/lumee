<?php

namespace App\Http\Middleware;

use App\Analytics\Visits;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Counts storefront page views into visits (see App\Analytics\Visits) and keeps the visit cookies fresh. */
class TrackStorefrontVisit
{
    public function __construct(private Visits $visits) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $html = str_contains((string) $response->headers->get('Content-Type'), 'text/html');
        if ($response->getStatusCode() === 200 && $html) {
            $this->visits->pageview($request);
        }
        $this->visits->attachCookies($response);
        if ($request->query('ref') !== null && ($store = app(\App\Tenancy\CurrentStore::class)->get())) {
            rescue(fn () => app(\App\Marketing\Affiliates::class)->track($request, $response, $store));   // affiliate links
        }

        return $response;
    }
}
