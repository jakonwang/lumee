<?php

use App\Http\Middleware\RequireStorefrontStore;
use App\Http\Middleware\ResetRequestScopedState;
use App\Http\Middleware\ResolveAdminStore;
use App\Http\Middleware\ResolveStorefrontStore;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(ResetRequestScopedState::class);
        // Behind Caddy / nginx: honour X-Forwarded-Proto so URLs are https.
        // Same machine by default; in Docker the proxy is another container, so set TRUSTED_PROXIES=* there.
        $trustedProxies = (string) env('TRUSTED_PROXIES', '');
        $middleware->trustProxies(at: match ($trustedProxies) {
            '' => ['127.0.0.1', '::1'],
            '*' => '*',
            default => array_map('trim', explode(',', $trustedProxies)),
        });
        // Admin & ops API speak the admin's language (Chinese default)
        $middleware->appendToGroup('api', \App\Http\Middleware\SetAdminLocale::class);

        $middleware->alias([
            'store.admin' => ResolveAdminStore::class,
            'store.front' => ResolveStorefrontStore::class,
            'store.required' => RequireStorefrontStore::class,
            'perm' => \App\Http\Middleware\RequirePermission::class,
            'customer' => \App\Http\Middleware\RequireCustomer::class,
            'billing.open' => \App\Http\Middleware\EnsureStoreOpen::class,
            'store.open' => \App\Http\Middleware\StorefrontOpen::class,
            'store.locale' => \App\Http\Middleware\SetStorefrontLocale::class,
            'store.analytics' => \App\Http\Middleware\TrackStorefrontVisit::class,
            'store.redirects' => \App\Http\Middleware\StorefrontRedirects::class,
            'admin.activity' => \App\Http\Middleware\LogAdminActivity::class,
            'platform.admin' => \App\Http\Middleware\RequirePlatformAdmin::class,
            'api.token' => \App\Http\Middleware\AuthenticateApiToken::class,
            'api.scope' => \App\Http\Middleware\RequireApiScope::class,
        ]);

        // Set by storefront JS ("stay here" on the market suggestion), so it can't be encrypted
        $middleware->encryptCookies(except: ['lumee_market_choice']);

        // Stripe signs its webhooks; CSRF tokens don't apply there.
        $middleware->validateCsrfTokens(except: ['webhooks/*', 'wholesale/catalog/*/orders']);

        // Tenant must be resolved BEFORE route-model binding, so {product} lookups
        // are scoped to the current store (prevents cross-store access by ID).
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveAdminStore::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, \App\Http\Middleware\AuthenticateApiToken::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveStorefrontStore::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
