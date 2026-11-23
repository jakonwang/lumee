<?php

namespace App\Http\Middleware;

use App\Analytics\Visits;
use App\Currency\Presentment;
use App\Customers\CustomerSession;
use App\Markets\MarketContext;
use App\Models\DesignExperiment;
use App\Services\CartService;
use App\Storefront\PageCache;
use App\Tenancy\CurrentStore;
use App\Themes\DesignExperiments;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves anonymous shoppers' catalog pages (home, products, collections, pages, blog) from a short-lived cache.
 * Anything per-visitor is either part of the key (site, language, currency, country, design A/B arm) or makes the
 * page uncached (signed-in customer, a cart, flash messages, previews, product A/B tests). The CSRF token is put
 * back per session, and a cached product page still counts as a product view.
 */
class CacheStorefrontPage
{
    private const ROUTES = ['store.home', 'store.product', 'store.collections', 'store.collection', 'store.page', 'store.blog', 'store.article'];

    private const TOKEN = '__LUMEE_CSRF_TOKEN__';

    public function __construct(private CurrentStore $current, private PageCache $pages) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->key($request);
        if ($key === null) {
            return $next($request);
        }

        if (is_array($hit = Cache::get($key))) {
            if ($hit['product'] ?? null) {
                app(Visits::class)->productView((int) $hit['product']);
            }

            return response(str_replace(self::TOKEN, csrf_token(), $hit['html']), 200, ['Content-Type' => $hit['type'], 'X-Page-Cache' => 'HIT']);
        }

        $queued = array_map('spl_object_id', Cookie::getQueuedCookies());
        $response = $next($request);
        if ($this->storable($response, $queued)) {
            $html = str_replace(csrf_token(), self::TOKEN, (string) $response->getContent());
            Cache::put($key, ['html' => $html, 'type' => $response->headers->get('Content-Type'), 'product' => $this->pages->productId],
                (int) config('lumee.page_cache.ttl', 180));
            $response->headers->set('X-Page-Cache', 'MISS');
        }

        return $response;
    }

    /** Cache key for this request, or null when it must be rendered fresh. */
    private function key(Request $request): ?string
    {
        $store = $this->current->get();
        $route = preg_replace('/^[lm]\./', '', (string) $request->route()?->getName());
        if (! PageCache::enabled() || ! $store || ! $request->isMethod('GET') || ! in_array($route, self::ROUTES, true)) {
            return null;
        }
        if ($request->attributes->get('theme_preview') || $request->attributes->get('theme_proposal')
            || collect($request->query())->keys()->contains(fn ($k) => str_starts_with((string) $k, '_'))) {
            return null;   // editor previews
        }
        if ($request->cookie(CartService::COOKIE) || CustomerSession::user()
            || ($request->hasSession() && ($request->session()->has('status') || $request->session()->has('error') || $request->session()->has('errors')))) {
            return null;   // the header shows their bag / account, or a message for them
        }
        $market = app(MarketContext::class)->market();
        $query = $request->query();
        ksort($query);

        return 'page:'.$store->id.':'.PageCache::version($store->id).':'.sha1(implode('|', [
            $request->getHost(), $request->path(), http_build_query($query),
            app()->getLocale(), $market?->id, app(Presentment::class)->currency(),
            strtoupper((string) $request->header('CF-IPCountry')), $request->cookie('lumee_market_choice'),   // "you're in Mexico" suggestion
            $this->designArm($request, $store->id, $market?->id),
        ]));
    }

    /** The visitor's version of a design A/B test running on this site ("" when none). */
    private function designArm(Request $request, int $storeId, ?int $marketId): string
    {
        $e = DesignExperiment::where('status', 'running')->where('market_id', $marketId)->latest('id')->first(['id']);
        if (! $e) {
            return '';
        }
        $visits = app(Visits::class);

        return $e->id.($visits->trackable($request) ? DesignExperiments::arm($e, (string) $visits->visitorId()) : 'a');
    }

    /** @param  list<int>  $queued  cookies queued before this page rendered (not set by it) */
    private function storable(Response $response, array $queued): bool
    {
        $setHere = array_diff(array_map('spl_object_id', Cookie::getQueuedCookies()), $queued);

        return ! $this->pages->skip && $response->getStatusCode() === 200
            && str_contains((string) $response->headers->get('Content-Type'), 'text/html')
            && ! $response->headers->getCookies() && ! $setHere;   // the page set a cookie: it's for this visitor
    }
}
