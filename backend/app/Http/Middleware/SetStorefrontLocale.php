<?php

namespace App\Http\Middleware;

use App\I18n\Localization;
use App\Tenancy\CurrentStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Storefront language from the URL: "/fr/…" = French (if the store publishes it), no prefix =
 * the store's default language. Unknown or unpublished prefixes 404; "/en/…" for an English
 * store redirects to the canonical unprefixed URL. Also rewrites internal links in the HTML.
 */
class SetStorefrontLocale
{
    /** Paths that are never language-prefixed (assets, webhooks, files for crawlers). */
    private const UNPREFIXED = '#^/(storage|css|js|build|brand|favicon|platform-|webhooks|robots\.txt|sitemap\.xml|up$)#';

    public function handle(Request $request, Closure $next): Response
    {
        $current = app(CurrentStore::class);
        $route = $request->route();
        $prefix = $route?->parameter('locale');
        $route?->forgetParameter('locale');   // controllers receive their own params positionally

        if (! $current->check()) {
            return $next($request);
        }
        $store = $current->getOrFail();
        $l10n = app(Localization::class);
        $default = Localization::storeDefault($store);

        if ($prefix !== null) {
            if ($prefix === $default) {
                return redirect(substr($request->getRequestUri(), strlen('/'.$prefix)) ?: '/', 301);
            }
            abort_unless(in_array($prefix, Localization::enabled($store), true), 404);
        }
        $locale = $prefix ?? $default;
        $l10n->begin($store, $locale);

        $response = $next($request);

        return $locale === $default ? $response : $this->localize($response, $l10n->prefix());
    }

    /** Keep shoppers in their language: internal href/action/redirects get the prefix. */
    private function localize(Response $response, string $prefix): Response
    {
        $fix = fn (string $path) => preg_match(self::UNPREFIXED, $path) || str_starts_with($path, $prefix.'/') || $path === $prefix
            ? $path : $prefix.($path === '/' ? '' : $path);

        if ($response->isRedirection() && ($location = $response->headers->get('Location'))) {
            $host = request()->getSchemeAndHttpHost();
            if (str_starts_with($location, $host.'/')) {
                $response->headers->set('Location', $host.$fix(substr($location, strlen($host))));
            } elseif (str_starts_with($location, '/') && ! str_starts_with($location, '//')) {
                $response->headers->set('Location', $fix($location));
            }

            return $response;
        }

        if (str_contains((string) $response->headers->get('Content-Type'), 'text/html') && method_exists($response, 'getContent')) {
            $html = preg_replace_callback('#\b(href|action)="(/(?!/)[^"]*)"#',
                fn ($m) => $m[1].'="'.$fix($m[2]).'"', (string) $response->getContent());
            $response->setContent($html);
        }

        return $response;
    }
}
