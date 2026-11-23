<?php

namespace App\Providers;

use App\Services\CartService;
use App\Services\MenuService;
use App\Tenancy\CurrentStore;
use App\Themes\ThemeContext;
use App\Themes\ThemeService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One tenant / one cart / one theme per request (scoped = reset between requests/jobs, Octane-safe)
        $this->app->scoped(CurrentStore::class);
        $this->app->scoped(CartService::class);
        $this->app->scoped(\App\Analytics\Visits::class);   // storefront visit + funnel tracking
        $this->app->scoped(\App\Promotions\Promotions::class);
        $this->app->scoped(\App\Pricing\PriceLists::class);
        $this->app->scoped(\App\B2B\B2B::class);   // signed-in business buyer, company, terms   // wholesale prices for the signed-in customer   // automatic discounts (sale prices on the storefront)
        $this->app->scoped(\App\Currency\Presentment::class);
        $this->app->scoped(\App\Markets\MarketContext::class);
        $this->app->scoped(\App\Themes\SectionData::class);
        $this->app->scoped(MenuService::class);   // resolved menus are remembered for the request
        $this->app->scoped(\App\Storefront\PageCache::class);   // what this request's page cache entry needs
        $this->app->scoped(\App\Themes\DesignExperiments::class);   // design A/B tests running on this request's site   // data the storefront blocks look up (memoized per request)
        $this->app->scoped(\App\Testing\Experiments::class);   // running A/B tests seen in this request   // the market (country site) of this storefront request
        $this->app->scoped(\App\I18n\Localization::class);     // shopper language + content translations   // the shopper's display currency
        $this->app->scoped(ThemeContext::class, fn ($app) => $app->make(ThemeService::class)->resolve(
            $app->make(CurrentStore::class)->getOrFail(),
            preview: (bool) $app['request']->attributes->get('theme_preview'),
            ref: $app['request']->attributes->get('theme_preview_theme'),
            proposal: $app['request']->attributes->get('theme_proposal'),
        ));
    }

    /**
     * Requests per minute for each rate-limited action. Each action has its own counter —
     * Laravel's plain "throttle:N,1" shares ONE counter across every throttled route for a
     * visitor, so e.g. adding items to the bag would eat into the checkout allowance.
     */
    private const RATE_LIMITS = [
        'register' => 10, 'login' => 10,
        'uploads' => 120, 'media' => 60, 'theme-save' => 120,
        'email-resend' => 20, 'email-test' => 10,
        'domains' => 20, 'domain-verify' => 30,
        'cart' => 60, 'discount-code' => 20, 'checkout' => 10,
        'staff-invite' => 20, 'invite-accept' => 20, 'imports' => 10, 'refunds' => 30,
        'customer-login' => 10, 'customer-register' => 10, 'customer-email' => 5,
        'billing' => 20, 'review-submit' => 5, 'newsletter' => 10, 'campaign-test' => 10, 'review-import' => 10,
    ];

    public function boot(): void
    {
        // Shoppers see changes right away: saving anything shown on the storefront drops the store's cached pages
        foreach ([\App\Models\Product::class, \App\Models\ProductVariant::class, \App\Models\ProductImage::class, \App\Models\ProductOption::class,
            \App\Models\Collection::class, \App\Models\StoreTheme::class, \App\Models\StoreThemeMarket::class, \App\Models\StoreMedia::class,
            \App\Models\Page::class, \App\Models\Menu::class, \App\Models\BlogPost::class, \App\Models\ProductReview::class,
            \App\Models\Market::class, \App\Models\MarketPrice::class, \App\Models\MarketProductExclusion::class, \App\Models\Translation::class,
            \App\Models\Promotion::class, \App\Models\Discount::class, \App\Models\DesignExperiment::class, \App\Models\ContentExperiment::class,
            \App\Models\ThemeSchedule::class, \App\Models\Store::class] as $model) {
            $bump = fn ($m) => \App\Storefront\PageCache::bump($m instanceof \App\Models\Store ? $m->id : $m->getAttribute('store_id'));
            $model::saved($bump);
            $model::deleted($bump);
        }

        foreach (self::RATE_LIMITS as $name => $perMinute) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($perMinute)
                ->by($name.'|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        }

        // Open API: 120 requests a minute per token
        RateLimiter::for('api-token', fn (Request $request) => Limit::perMinute(120)
            ->by('api|'.($request->attributes->get('api_token')?->id ?? $request->ip()))
            ->response(fn () => response()->json(['error' => 'rate_limited', 'message' => 'Too many requests — 120 per minute per token.'], 429)));

        // Every storefront theme template gets the resolved theme settings
        View::composer('themes.*', function ($view) {
            if (app(CurrentStore::class)->check()) {
                $view->with('theme', app(ThemeContext::class));
                $view->with('px', app(\App\Currency\Presentment::class));   // $px->display($cents) in the shopper's currency
            }
        });

        // Bag count in every storefront theme header
        View::composer('themes.*.layout', function ($view) {
            $hasStore = app(CurrentStore::class)->check();
            $menus = app(MenuService::class);
            $view->with([
                'cartCount' => $hasStore ? (app(CartService::class)->current()?->itemCount() ?? 0) : 0,
                'customer' => $hasStore ? \App\Customers\CustomerSession::user() : null,
                'mainMenu' => $hasStore ? $menus->resolve('main') : [],
                'footerMenu' => $hasStore ? $menus->resolve('footer') : [],
            ]);
        });
    }
}
