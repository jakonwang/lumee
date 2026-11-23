<?php

namespace App\Http\Middleware;

use App\Tenancy\CurrentStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers on every response.
 *  - nosniff: browsers don't guess file types (an upload can't run as a script).
 *  - Referrer-Policy: other sites see only our origin, never full URLs (order / reset-password tokens are in some).
 *  - Permissions-Policy: no camera, microphone or location for any page.
 *  - HSTS on HTTPS: browsers stop trying plain http.
 *  - Framing: storefront pages may be framed by the store itself and the admin (the theme editor previews them);
 *    everything else (API, platform pages) not at all — no clickjacking.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $h = $response->headers;

        $h->set('X-Content-Type-Options', 'nosniff', false);
        $h->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);
        $h->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()', false);
        if ($request->isSecure()) {
            $h->set('Strict-Transport-Security', 'max-age=15552000', false);
        }
        if (! $h->has('Content-Security-Policy') && ! $h->has('X-Frame-Options')) {
            $html = str_contains((string) $h->get('Content-Type'), 'text/html');
            $h->set('Content-Security-Policy', $html && app(CurrentStore::class)->check()
                ? "frame-ancestors 'self' ".self::adminOrigin()
                : "frame-ancestors 'none'");
        }

        return $response;
    }

    /** The admin's origin (scheme://host[:port]), allowed to frame storefront pages for the editor preview. */
    public static function adminOrigin(): string
    {
        $u = parse_url((string) config('lumee.admin_url'));

        return ($u['scheme'] ?? 'https').'://'.($u['host'] ?? 'localhost').(isset($u['port']) ? ':'.$u['port'] : '');
    }
}
