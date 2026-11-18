<?php

namespace App\Http\Middleware;

use App\Models\UrlRedirect;
use App\Tenancy\CurrentStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * URL redirects: when a storefront page would be "not found", send shoppers (and search engines)
 * to the merchant's new address with a 301. Only GET/HEAD, only paths that really 404.
 */
class StorefrontRedirects
{
    public function __construct(private CurrentStore $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (NotFoundHttpException $e) {
            return $this->redirect($request) ?? throw $e;
        }

        return $response->getStatusCode() === 404 ? ($this->redirect($request) ?? $response) : $response;
    }

    private function redirect(Request $request): ?Response
    {
        if (! $this->current->check() || ! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return null;
        }
        $path = UrlRedirect::normalize($request->getPathInfo());
        $candidates = [$path];
        // "/fr/old-page" also matches a redirect saved as "/old-page"
        if (preg_match('~^/('.implode('|', array_keys(config('lumee.languages'))).')(/.*)$~', $path, $m)) {
            $candidates[] = $m[2];
        }
        $hit = UrlRedirect::whereIn('path', $candidates)->first();
        if (! $hit) {
            return null;
        }
        UrlRedirect::whereKey($hit->id)->update(['hits' => \DB::raw('hits + 1'), 'last_hit_at' => now()]);

        return redirect()->away($hit->target, 301)->setPrivate();
    }
}
