<?php

namespace App\Http\Middleware;

use App\Models\Domain;
use App\Models\Store;
use App\Tenancy\CurrentStore;
use App\Themes\ThemeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Storefront: resolves the store from the request Host
 * (custom domain or {handle}.{storefront_domain}). In local env, ?store=handle also works.
 */
class ResolveStorefrontStore
{
    public function __construct(private CurrentStore $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());

        // Only verified domains serve a store (a merchant must prove they control a custom domain)
        $store = Domain::where('host', $host)->whereNotNull('verified_at')->first()?->store;
        $viaCustomDomain = $store !== null && ! str_ends_with($host, '.'.config('lumee.storefront_domain'));

        if (! $store) {
            $suffix = '.'.config('lumee.storefront_domain');
            if (str_ends_with($host, $suffix)) {
                $store = Store::where('handle', substr($host, 0, -strlen($suffix)))->first();
            }
        }

        if (! $store && app()->isLocal() && $request->query('store')) {
            $store = Store::where('handle', $request->query('store'))->first();
        }

        // Always assign (even null) so a store from a previous request can never leak in.
        // Suspended stores still resolve, so StorefrontOpen can show "temporarily closed".
        $store = in_array($store?->status, ['active', 'suspended'], true) ? $store : null;

        // Custom domain on a plan without custom domains (e.g. after a downgrade) → the free subdomain
        if ($store && $viaCustomDomain && ! app(\App\Billing\Billing::class)->limits($store)['custom_domains']
            && app(\App\Billing\Billing::class)->isOpen($store)) {
            $port = config('lumee.storefront_port');

            return redirect()->away(config('lumee.storefront_scheme').'://'.$store->handle.'.'.config('lumee.storefront_domain')
                .($port ? ':'.$port : '').$request->getRequestUri(), 302);
        }
        $this->current->set($store);
        view()->share('store', $store);
        if ($store) {
            app(\App\Promotions\Promotions::class)->enableFor($store);   // sale prices + automatic discounts
        }

        // Theme editor preview: a valid signed token shows the unpublished draft
        $previewTheme = $store ? app(ThemeService::class)->verifyPreviewToken($store, $request->query('_preview')) : null;
        $preview = $previewTheme !== null;
        $request->attributes->set('theme_preview', $preview);
        $request->attributes->set('theme_preview_theme', $previewTheme);

        $response = $next($request);

        if ($preview) {
            $response->headers->set('Cache-Control', 'no-store');
            $response->headers->set('X-Robots-Tag', 'noindex');
        }

        return $response;
    }
}
