<?php

use App\Http\Controllers\Storefront\AccountController;
use App\Http\Controllers\Storefront\CartController;
use App\Http\Controllers\Storefront\CheckoutController;
use App\Http\Controllers\Storefront\CollectionController;
use App\Http\Controllers\Storefront\ContentController;
use App\Http\Controllers\Storefront\StorefrontController;
use App\Http\Controllers\Webhooks\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Storefront — the store is resolved from the Host header
| ({handle}.lumee.shop or a merchant's custom domain). See ResolveStorefrontStore.
*/
$storefront = function () {
    Route::get('/', [StorefrontController::class, 'home'])->name('store.home');

    Route::middleware('store.required')->group(function () {
        Route::get('/products/{handle}', [StorefrontController::class, 'product'])->name('store.product');
        Route::get('/products/{handle}/review', [\App\Http\Controllers\Storefront\ReviewController::class, 'fromEmail']);
        Route::post('/products/{handle}/reviews', [\App\Http\Controllers\Storefront\ReviewController::class, 'store'])->middleware('throttle:review-submit');
        Route::get('/collections', [CollectionController::class, 'index'])->name('store.collections');
        Route::get('/collections/{handle}', [CollectionController::class, 'show'])->name('store.collection');
        Route::get('/pages/{handle}', [ContentController::class, 'page'])->name('store.page');
        Route::get('/s/{slug}', [\App\Http\Controllers\Storefront\SeoPageController::class, 'show'])->where('slug', '[A-Za-z0-9\-]+');   // SEO landing pages
        Route::get('/search', [ContentController::class, 'search'])->name('store.search');
        Route::get('/blog', [\App\Http\Controllers\Storefront\BlogController::class, 'index'])->name('store.blog');
        Route::get('/blog/feed.xml', [\App\Http\Controllers\Storefront\BlogController::class, 'feed']);
        Route::get('/blog/{handle}', [\App\Http\Controllers\Storefront\BlogController::class, 'show'])->name('store.article');
        Route::get('/feeds/google.xml', [\App\Http\Controllers\Storefront\FeedController::class, 'google'])->middleware('throttle:60,1');
        Route::get('/feeds/meta.xml', [\App\Http\Controllers\Storefront\FeedController::class, 'meta'])->middleware('throttle:60,1');
        Route::get('/feeds/tiktok.csv', [\App\Http\Controllers\Storefront\FeedController::class, 'tiktok'])->middleware('throttle:60,1');

        Route::get('/cart', [CartController::class, 'show'])->name('store.cart');
        Route::post('/currency', [ContentController::class, 'currency']);
        Route::post('/cart/add', [CartController::class, 'add'])->middleware('throttle:cart');
        Route::post('/cart/add-many', [CartController::class, 'addMany'])->middleware('throttle:cart');
        Route::post('/cart/items/{item}', [CartController::class, 'update'])->whereNumber('item');
        Route::post('/cart/discount', [CartController::class, 'applyDiscount'])->middleware('throttle:discount-code');
        Route::post('/cart/discount/remove', [CartController::class, 'removeDiscount']);
        Route::post('/cart/gift-card/remove', [CartController::class, 'removeGiftCard']);

        Route::get('/checkout', [CheckoutController::class, 'show'])->name('store.checkout');
        Route::get('/checkout/shipping-rates', [CheckoutController::class, 'rates'])->middleware('throttle:cart');
        Route::post('/checkout', [CheckoutController::class, 'store'])->middleware('throttle:checkout');
        Route::post('/newsletter', [\App\Http\Controllers\Storefront\NewsletterController::class, 'subscribe'])->middleware('throttle:newsletter');
        Route::post('/checkout/contact', [CheckoutController::class, 'contact'])->middleware('throttle:cart');
        Route::get('/cart/recover/{token}', [CheckoutController::class, 'recover'])->whereUuid('token')->middleware('throttle:cart');
        Route::get('/checkout/airwallex/return', [CheckoutController::class, 'airwallexReturn'])->middleware('throttle:checkout');
        Route::get('/checkout/airwallex/{token}', [CheckoutController::class, 'airwallexHandoff'])->whereUuid('token');
        Route::get('/checkout/razorpay/return', [CheckoutController::class, 'razorpayReturn'])->middleware('throttle:checkout');
        Route::get('/checkout/paypal/return', [CheckoutController::class, 'paypalReturn'])->middleware('throttle:checkout');
        Route::get('/subscriptions/{token}', [\App\Http\Controllers\Storefront\SubscriptionController::class, 'show'])->whereUuid('token');
        Route::post('/subscriptions/{token}', [\App\Http\Controllers\Storefront\SubscriptionController::class, 'update'])->whereUuid('token')->middleware('throttle:20,1');
        $b2b = \App\Http\Controllers\Storefront\B2BController::class;
        Route::get('/wholesale', [$b2b, 'applyForm']);
        Route::get('/wholesale/order', [$b2b, 'quickOrderForm']);
        Route::post('/wholesale/order', [$b2b, 'quickOrder'])->middleware('throttle:cart');
        $wc = \App\Http\Controllers\Storefront\WholesaleCatalogController::class;   // shareable catalogs + distributor feed
        Route::get('/wholesale/catalog/{token}.json', [$wc, 'json'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:60,1');
        Route::get('/wholesale/catalog/{token}.csv', [$wc, 'csv'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:60,1');
        Route::get('/wholesale/catalog/{token}', [$wc, 'show'])->where('token', '[A-Za-z0-9]{40}');
        Route::post('/wholesale/catalog/{token}/orders', [$wc, 'placeOrder'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:30,1');   // distributor order API (order key)
        Route::get('/wholesale/catalog/{token}/orders/{number}', [$wc, 'order'])->where('token', '[A-Za-z0-9]{40}')->whereNumber('number')->middleware('throttle:60,1');
        Route::get('/supplier/{token}', [\App\Http\Controllers\Storefront\SupplierPortalController::class, 'show'])->where('token', '[A-Za-z0-9]{40}');
        Route::post('/supplier/{token}/orders/{order}', [\App\Http\Controllers\Storefront\SupplierPortalController::class, 'update'])->where('token', '[A-Za-z0-9]{40}')->whereNumber('order')->middleware('throttle:30,1');
        Route::post('/supplier/{token}/products', [\App\Http\Controllers\Storefront\SupplierPortalController::class, 'offer'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:20,1');
        Route::post('/wholesale', [$b2b, 'apply'])->middleware('throttle:5,1');
        Route::post('/quotes', [$b2b, 'requestQuote'])->middleware('throttle:10,1');
        Route::get('/quotes/{token}', [$b2b, 'show'])->whereUuid('token');
        Route::post('/quotes/{token}/accept', [$b2b, 'accept'])->whereUuid('token')->middleware('throttle:10,1');
        Route::post('/quotes/{token}/decline', [$b2b, 'decline'])->whereUuid('token');
        Route::post('/products/{handle}/notify', [StorefrontController::class, 'notifyMe'])->middleware('throttle:10,1');
        Route::get('/wishlist', [StorefrontController::class, 'wishlist']);
        Route::post('/assistant', [\App\Http\Controllers\Storefront\AssistantController::class, 'ask'])->middleware('throttle:20,1');   // AI shopping assistant
        Route::get('/wishlist/products', [StorefrontController::class, 'wishlistProducts'])->middleware('throttle:60,1');
        Route::get('/orders/{token}', [CheckoutController::class, 'order'])->whereUuid('token')->name('store.order');
        Route::post('/orders/{token}/pay', [CheckoutController::class, 'pay'])->whereUuid('token')->middleware('throttle:checkout');   // back to the payment page
        Route::get('/affiliate/{token}', [StorefrontController::class, 'affiliate'])->where('token', '[A-Za-z0-9]{48}');
        Route::get('/downloads/{token}', [StorefrontController::class, 'download'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:30,1');
        Route::post('/orders/{token}/returns', [\App\Http\Controllers\Storefront\ReturnController::class, 'store'])->whereUuid('token')->middleware('throttle:10,1');

        // Customer accounts
        Route::prefix('account')->group(function () {
            Route::get('login', [AccountController::class, 'showLogin'])->name('store.login');
            Route::get('google', [\App\Http\Controllers\Storefront\GoogleLoginController::class, 'start'])->middleware('throttle:customer-login');
            Route::get('google/complete', [\App\Http\Controllers\Storefront\GoogleLoginController::class, 'complete'])->middleware('throttle:customer-login');
            Route::post('login', [AccountController::class, 'login'])->middleware('throttle:customer-login');
            Route::get('register', [AccountController::class, 'showRegister']);
            Route::post('register', [AccountController::class, 'register'])->middleware('throttle:customer-register');
            Route::post('logout', [AccountController::class, 'logout']);
            Route::get('verify/{token}', [AccountController::class, 'verify'])->where('token', '[A-Za-z0-9]{48}');
            Route::get('forgot-password', [AccountController::class, 'showForgot']);
            Route::post('forgot-password', [AccountController::class, 'forgot'])->middleware('throttle:customer-email');
            Route::get('reset/{token}', [AccountController::class, 'showReset'])->where('token', '[A-Za-z0-9]{48}');
            Route::post('reset/{token}', [AccountController::class, 'reset'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:customer-login');

            Route::middleware('customer')->group(function () {
                Route::get('/', [AccountController::class, 'index'])->name('store.account');
                Route::post('profile', [AccountController::class, 'updateProfile']);
                Route::post('address', [AccountController::class, 'updateAddress']);
                Route::post('password', [AccountController::class, 'updatePassword'])->middleware('throttle:customer-login');
                Route::post('verify/resend', [AccountController::class, 'resendVerification'])->middleware('throttle:customer-email');
                Route::post('points/redeem', [AccountController::class, 'redeemPoints'])->middleware('throttle:10,1');
                Route::post('credit/{id}/apply', [AccountController::class, 'applyCredit'])->whereNumber('id');
                Route::get('data', [AccountController::class, 'downloadData'])->middleware('throttle:6,1');
                Route::post('delete', [AccountController::class, 'requestDeletion'])->middleware('throttle:3,1');
            });
        });
    });
};

// Marketing email links: work even when the store is paused (people must always be able to unsubscribe)
Route::middleware(['store.front', 'store.required'])->prefix('email')->group(function () {
    $n = \App\Http\Controllers\Storefront\NewsletterController::class;
    Route::get('unsubscribe/{token}', [$n, 'unsubscribePage'])->where('token', '[A-Za-z0-9]{40}');
    Route::post('unsubscribe/{token}', [$n, 'unsubscribe'])->where('token', '[A-Za-z0-9]{40}')
        ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);   // one-click POST from mail clients
    Route::get('open/{token}.gif', [$n, 'open'])->where('token', '[A-Za-z0-9]{40}');
    Route::get('click/{token}', [$n, 'click'])->where('token', '[A-Za-z0-9]{40}');
});

// Default language at "/", extra languages under a prefix ("/fr/products/…") — same routes
Route::middleware(['store.front', 'store.redirects', 'store.locale', 'store.open', 'store.analytics', 'store.cache'])->group(function () use ($storefront) {
    // Served per store (the static public/robots.txt was removed so this route answers)
    Route::get('/robots.txt', [ContentController::class, 'robots'])->name('store.robots');
    Route::get('/sitemap.xml', [ContentController::class, 'sitemap'])->middleware('store.required');
    // Installable app (PWA): manifest, icons, offline page, service worker (only at the root, so it covers every site prefix)
    $pwa = \App\Http\Controllers\Storefront\PwaController::class;
    Route::get('/manifest.webmanifest', [$pwa, 'manifest'])->middleware('store.required');
    Route::get('/pwa-icon/{size}.png', [$pwa, 'icon'])->whereNumber('size')->middleware('store.required');
    Route::get('/sw.js', [$pwa, 'worker'])->middleware('store.required');
    Route::get('/offline', [$pwa, 'offline'])->middleware('store.required');
    $storefront();
});
Route::prefix('{locale}')
    ->where(['locale' => implode('|', array_keys(config('lumee.languages')))])
    ->middleware(['store.front', 'store.redirects', 'store.locale', 'store.open', 'store.analytics', 'store.cache'])
    ->name('l.')
    ->group($storefront);
// Markets (country sites): "/es-mx/products/…" = the Mexico market in Spanish — see SetStorefrontLocale
Route::prefix('{site}')
    ->where(['site' => '(?:'.implode('|', array_keys(config('lumee.languages'))).')-[a-z]{2,12}'])
    ->middleware(['store.front', 'store.redirects', 'store.locale', 'store.open', 'store.analytics', 'store.cache'])
    ->name('m.')
    ->group($storefront);

// Storefront image sizes (any host; no session or cookies, so browsers and CDNs can keep them for a year)
Route::get('/_img/{width}/{path}', \App\Http\Controllers\Storefront\ImageVariantController::class)
    ->whereNumber('width')->where('path', 'stores/.+')
    ->withoutMiddleware([\Illuminate\Session\Middleware\StartSession::class, \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class])
    ->middleware('throttle:600,1');

// Google sign-in for shoppers comes back here on the platform host, then continues on the shop's own host
Route::get('/auth/google/callback', [\App\Http\Controllers\Storefront\GoogleLoginController::class, 'callback'])->middleware('throttle:60,1');

// Payment provider webhooks (no CSRF, no store context — see controller)
Route::post('/webhooks/stripe', StripeWebhookController::class);
Route::post('/webhooks/airwallex', \App\Http\Controllers\Webhooks\AirwallexWebhookController::class)->middleware('throttle:120,1');
Route::post('/webhooks/17track', \App\Http\Controllers\Webhooks\TrackingWebhookController::class)->middleware('throttle:600,1');

// Caddy on-demand TLS: may this host get a certificate? (see docs/https.md)
Route::get('/internal/tls/ask', \App\Http\Controllers\TlsAskController::class)->middleware('throttle:600,1');
Route::post('/webhooks/paypal', \App\Http\Controllers\Webhooks\PayPalWebhookController::class)->middleware('throttle:60,1');

// Unknown paths on a storefront: give URL redirects a chance before the 404
Route::fallback(\App\Http\Controllers\Storefront\NotFoundController::class)->middleware(['store.front', 'store.redirects']);
