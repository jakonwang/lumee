<?php

use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CollectionController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\MenuController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DiscountController;
use App\Http\Controllers\Admin\DomainController;
use App\Http\Controllers\Admin\StoreSettingsController;
use App\Http\Controllers\Admin\ImageUploadController;
use App\Http\Controllers\Admin\InvitationController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\MediaUploadController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\ThemeController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductImportController;
use App\Http\Controllers\Admin\PaymentSettingsController;
use App\Http\Controllers\Admin\PageController;
use App\Http\Controllers\Admin\CurrencySettingsController;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\TranslationController;
use App\Http\Controllers\Admin\TaxController;
use App\Http\Controllers\Ops\OpsController;
use App\Http\Controllers\Admin\ShippingController;
use Illuminate\Support\Facades\Route;

/*
| Merchant admin API — consumed by the Vue admin app (/admin).
| Store context comes from the X-Store-Id header (see ResolveAdminStore).
*/
// Browser extension (采集插件): sends the product page the merchant has open. Its own key, not a login.
Route::post('collector/pages', [\App\Http\Controllers\Admin\CollectController::class, 'fromExtension'])->middleware('throttle:60,1');

Route::prefix('admin')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('auth/two-factor', [AuthController::class, 'twoFactor'])->middleware('throttle:login');
    Route::get('invitations/{token}', [InvitationController::class, 'show'])->middleware('throttle:invite-accept');
    Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])->middleware('throttle:invite-accept');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);

        // Across all of the user's stores
        $org = \App\Http\Controllers\Admin\OrganizationController::class;
        Route::post('stores', [$org, 'createStore'])->middleware('throttle:10,1');
        Route::get('organization/overview', [$org, 'overview']);

        // Developers: their own apps (not tied to a store)
        $partner = \App\Http\Controllers\Admin\PartnerAppController::class;
        Route::get('partner/apps', [$partner, 'index']);
        Route::post('partner/apps', [$partner, 'store'])->middleware('throttle:20,1');
        Route::put('partner/apps/{id}', [$partner, 'update'])->whereNumber('id');
        Route::delete('partner/apps/{id}', [$partner, 'destroy'])->whereNumber('id');
        Route::post('partner/apps/{id}/secret', [$partner, 'rotateSecret'])->whereNumber('id')->middleware('throttle:20,1');
        Route::post('partner/apps/{id}/submit', [$partner, 'submit'])->whereNumber('id');
        Route::get('partner/payouts', [\App\Http\Controllers\Admin\PartnerPayoutController::class, 'show']);
        Route::put('partner/payouts/details', [\App\Http\Controllers\Admin\PartnerPayoutController::class, 'updateDetails'])->middleware('throttle:10,1');
        $pt = \App\Http\Controllers\Admin\PartnerThemeController::class;
        Route::get('partner/themes', [$pt, 'index']);
        Route::post('partner/themes', [$pt, 'store'])->middleware('throttle:20,1');
        Route::put('partner/themes/{id}', [$pt, 'update'])->whereNumber('id');
        Route::post('partner/themes/{id}/package', [$pt, 'upload'])->whereNumber('id')->middleware('throttle:20,1');
        Route::post('partner/themes/{id}/submit', [$pt, 'submit'])->whereNumber('id');
        Route::delete('partner/themes/{id}', [$pt, 'destroy'])->whereNumber('id');
        Route::get('partner/apps/{id}/reviews', [$partner, 'reviews'])->whereNumber('id');
        Route::put('partner/apps/{id}/reviews/{reviewId}/reply', [$partner, 'reply'])->whereNumber('id')->whereNumber('reviewId')->middleware('throttle:30,1');
        Route::get('me', [AuthController::class, 'me']);

        // Two-step sign-in for the signed-in staff account
        $tf = \App\Http\Controllers\Admin\TwoFactorController::class;
        Route::get('account/two-factor', [$tf, 'show']);
        Route::post('account/two-factor', [$tf, 'start'])->middleware('throttle:10,1');
        Route::post('account/two-factor/confirm', [$tf, 'confirm'])->middleware('throttle:10,1');
        Route::post('account/two-factor/recovery-codes', [$tf, 'recoveryCodes'])->middleware('throttle:10,1');
        Route::delete('account/two-factor', [$tf, 'disable'])->middleware('throttle:10,1');

        Route::middleware(['store.admin', 'admin.activity'])->group(function () {
            // Billing works even when the store is paused (that's how it gets un-paused)
            Route::get('billing', [BillingController::class, 'show']);
            Route::middleware('perm:owner')->group(function () {
                Route::post('billing/subscribe', [BillingController::class, 'subscribe'])->middleware('throttle:billing');
                Route::post('billing/cancel', [BillingController::class, 'cancel'])->middleware('throttle:billing');
                Route::post('billing/resume', [BillingController::class, 'resume'])->middleware('throttle:billing');
            });

            Route::middleware('billing.open')->group(function () {
            // Everyone in the store: home (the payload only includes what the member may see)
            Route::get('dashboard', DashboardController::class);

            Route::middleware('perm:analytics')->get('analytics', AnalyticsController::class);
            Route::middleware('perm:analytics')->get('analytics/retention', \App\Http\Controllers\Admin\RetentionController::class);
            Route::middleware('perm:analytics')->group(function () {   // ad performance: spend vs orders per platform, market, campaign
                $ads = \App\Http\Controllers\Admin\AdPerformanceController::class;
                Route::get('ads/report', [$ads, 'report']);
                Route::post('ads/spend/import', [$ads, 'import'])->middleware('throttle:20,1');
                Route::post('ads/spend', [$ads, 'store']);
                Route::delete('ads/spend/{id}', [$ads, 'destroy'])->whereNumber('id');
            });

            Route::middleware('perm:products')->group(function () {
                // Bulk import/export (registered before the resource so "products/export" isn't read as an id)
                Route::get('products/export', [ProductImportController::class, 'export']);
                Route::post('products/copy', [\App\Http\Controllers\Admin\OrganizationController::class, 'copyProducts'])->middleware('throttle:imports');
                Route::get('products/import/template', [ProductImportController::class, 'template']);
                Route::post('products/import', [ProductImportController::class, 'store'])->middleware('throttle:imports');
                Route::get('products/import/{id}', [ProductImportController::class, 'show'])->whereNumber('id');
                Route::post('products/import/{id}/start', [ProductImportController::class, 'start'])->whereNumber('id');
                Route::apiResource('products', ProductController::class);
                $pu = \App\Http\Controllers\Admin\PurchasingController::class;   // purchasing: suppliers, POs (采购)
                Route::get('purchasing', [$pu, 'index']);
                Route::get('suppliers', fn () => response()->json(['data' => \App\Models\Supplier::orderBy('name')->get(['id', 'name'])]));   // picker
                Route::post('suppliers', [$pu, 'saveSupplier']);
                Route::put('suppliers/{supplier}', [$pu, 'updateSupplier'])->whereNumber('supplier');
                Route::delete('suppliers/{supplier}', [$pu, 'destroySupplier'])->whereNumber('supplier');
                Route::post('suppliers/{supplier}/link', [$pu, 'resetSupplierLink'])->whereNumber('supplier');
                Route::post('suppliers/{supplier}/products', [$pu, 'assignProducts'])->whereNumber('supplier');
                Route::post('purchase-orders', [$pu, 'createOrder']);
                Route::post('purchase-orders/{order}/action', [$pu, 'orderAction'])->whereNumber('order');
                $wc = \App\Http\Controllers\Admin\WholesaleCatalogController::class;   // wholesale catalogs (批发目录)
                Route::get('wholesale-catalogs', [$wc, 'index']);
                Route::post('wholesale-catalogs', [$wc, 'store']);
                Route::put('wholesale-catalogs/{catalog}', [$wc, 'update'])->whereNumber('catalog');
                Route::delete('wholesale-catalogs/{catalog}', [$wc, 'destroy'])->whereNumber('catalog');
                Route::post('wholesale-catalogs/{catalog}/order-key', [$wc, 'orderKey'])->whereNumber('catalog');
                $rs = \App\Http\Controllers\Admin\ResearchController::class;   // research radar (选品雷达)
                Route::get('research', [$rs, 'index']);
                Route::put('research/settings', [$rs, 'saveSettings']);
                Route::post('research/discover', [$rs, 'discover'])->middleware('throttle:20,1');
                Route::post('research/opportunities', [$rs, 'store']);
                Route::post('research/import', [$rs, 'import'])->middleware('throttle:20,1');   // CSV from TikTok / Keepa / Helium 10…
                Route::put('research/opportunities/{opportunity}', [$rs, 'update'])->whereNumber('opportunity');
                Route::delete('research/opportunities/{opportunity}', [$rs, 'destroy'])->whereNumber('opportunity');
                Route::post('research/enrich', [$rs, 'enrich'])->middleware('throttle:20,1');
                Route::post('research/analyze', [$rs, 'analyze'])->middleware('throttle:20,1');
                $tl = \App\Http\Controllers\Admin\TestingController::class;   // test-and-learn (测款)
                Route::get('testing', [$tl, 'index']);
                Route::put('testing/settings', [$tl, 'saveSettings']);
                Route::post('testing/bulk', [$tl, 'bulk']);
                Route::get('testing/search-terms', [$tl, 'searchTerms']);
                Route::get('testing/products/{product}', [$tl, 'product']);
                Route::post('products/{product}/experiments', [$tl, 'startExperiment']);
                Route::post('experiments/{experiment}/finish', [$tl, 'finishExperiment'])->whereNumber('experiment');
                $ct = \App\Http\Controllers\Admin\ContentController::class;   // content workshop (内容工坊)
                Route::get('content/brand-kit', [$ct, 'brandKit']);
                Route::put('content/brand-kit', [$ct, 'saveBrandKit']);
                Route::get('content/products', [$ct, 'products']);
                Route::post('content/rescore', [$ct, 'rescore'])->middleware('throttle:10,1');
                Route::post('content/images/standardize', [$ct, 'standardize'])->middleware('throttle:30,1');
                Route::get('products/{product}/content', [$ct, 'product']);
                Route::post('products/{product}/content/suggest', [$ct, 'suggest'])->middleware('throttle:30,1');
                Route::post('products/{product}/content/apply', [$ct, 'apply']);
                Route::post('products/{product}/content/versions/{version}/restore', [$ct, 'restore'])->whereNumber('version');
                Route::post('products/{product}/images/{image}/revert', [$ct, 'revertImage'])->whereNumber('image');
                Route::post('products/{product}/images/{image}/scene', [$ct, 'sceneImage'])->whereNumber('image')->middleware('throttle:10,1');   // AI scene / model picture
                $cl = \App\Http\Controllers\Admin\CollectController::class;   // product collection (采集)
                Route::get('collect/items', [$cl, 'index']);
                Route::post('collect/items', [$cl, 'store'])->middleware('throttle:imports');
                Route::post('collect/items/bulk', [$cl, 'bulk'])->middleware('throttle:60,1');
                Route::get('collect/items/{item}', [$cl, 'show'])->whereNumber('item');
                Route::put('collect/items/{item}', [$cl, 'update'])->whereNumber('item');
                Route::get('collect/pricing-rules', [$cl, 'rules']);
                Route::put('collect/pricing-rules', [$cl, 'saveRules']);
                Route::post('collect/extension-key', [$cl, 'extensionKey'])->middleware('throttle:10,1');
                Route::get('products/{product}/markets', [\App\Http\Controllers\Admin\MarketController::class, 'product']);
                Route::put('products/{product}/markets', [\App\Http\Controllers\Admin\MarketController::class, 'updateProduct']);
                Route::post('products/{product}/market-fit', [\App\Http\Controllers\Admin\MarketController::class, 'productFit'])->middleware('throttle:30,1');
                Route::post('products-market-fit', [\App\Http\Controllers\Admin\MarketController::class, 'productsFit'])->middleware('throttle:20,1');
                $pl = \App\Http\Controllers\Admin\PriceListController::class;
                Route::get('price-lists', [$pl, 'index']);
                Route::post('price-lists', [$pl, 'store']);
                Route::get('price-lists/variants', [\App\Http\Controllers\Admin\ManualOrderController::class, 'variants']);
                Route::get('price-lists/{id}', [$pl, 'show'])->whereNumber('id');
                Route::put('price-lists/{id}', [$pl, 'update'])->whereNumber('id');
                Route::delete('price-lists/{id}', [$pl, 'destroy'])->whereNumber('id');
                $gc = \App\Http\Controllers\Admin\GiftCardController::class;
                Route::get('gift-cards', [$gc, 'index']);
                Route::post('gift-cards', [$gc, 'store'])->middleware('throttle:60,1');
                Route::get('gift-cards/{id}', [$gc, 'show'])->whereNumber('id');
                Route::patch('gift-cards/{id}', [$gc, 'update'])->whereNumber('id');
                Route::post('gift-cards/{id}/resend', [$gc, 'resend'])->whereNumber('id')->middleware('throttle:email-resend');
                Route::get('products/{product}/inventory', [\App\Http\Controllers\Admin\InventoryController::class, 'history']);
                Route::post('products/{product}/inventory', [\App\Http\Controllers\Admin\InventoryController::class, 'adjust']);
                Route::post('products/{product}/files', [\App\Http\Controllers\Admin\ProductFileController::class, 'store'])->middleware('throttle:30,1');
                Route::delete('products/{product}/files/{id}', [\App\Http\Controllers\Admin\ProductFileController::class, 'destroy'])->whereNumber('id');
                Route::apiResource('collections', CollectionController::class);
                Route::post('uploads/images', ImageUploadController::class)->middleware('throttle:uploads');
                Route::get('reviews', [\App\Http\Controllers\Admin\ReviewController::class, 'index']);
                Route::post('reviews/bulk', [\App\Http\Controllers\Admin\ReviewController::class, 'bulk']);
                Route::post('reviews/import', [\App\Http\Controllers\Admin\ReviewController::class, 'import'])->middleware('throttle:review-import');
                Route::put('reviews/settings', [\App\Http\Controllers\Admin\ReviewController::class, 'settings']);
                Route::patch('reviews/{id}', [\App\Http\Controllers\Admin\ReviewController::class, 'update'])->whereNumber('id');
                Route::delete('reviews/{id}', [\App\Http\Controllers\Admin\ReviewController::class, 'destroy'])->whereNumber('id');
            });
            // Collection list is also needed by the theme editor and menu editor
            Route::middleware('perm:products,online_store,marketing')->group(function () {
                Route::get('collections-list', [CollectionController::class, 'index']);
                Route::post('uploads/media', MediaUploadController::class)->middleware('throttle:media');
            });

            Route::middleware('perm:marketing')->apiResource('discounts', DiscountController::class)->except('show');
            Route::middleware('perm:customers,marketing')->group(function () {   // outreach sequences (开发信)
                $out = \App\Http\Controllers\Admin\OutreachController::class;
                Route::get('outreach', [$out, 'index']);
                Route::put('outreach/mailbox', [$out, 'saveMailbox'])->middleware('throttle:30,1');
                Route::post('outreach/mailbox/test', [$out, 'testMailbox'])->middleware('throttle:10,1');
                Route::post('outreach/sequences', [$out, 'store']);
                Route::put('outreach/sequences/{id}', [$out, 'update'])->whereNumber('id');
                Route::delete('outreach/sequences/{id}', [$out, 'destroy'])->whereNumber('id');
                Route::get('outreach/sequences/{id}/enrollments', [$out, 'enrollments'])->whereNumber('id');
                Route::post('outreach/sequences/{id}/enroll', [$out, 'enroll'])->whereNumber('id');
                Route::post('outreach/enrollments/{id}/stop', [$out, 'stop'])->whereNumber('id');
                Route::get('outreach/enrollments/{id}/preview', [$out, 'preview'])->whereNumber('id')->middleware('throttle:30,1');
                Route::patch('lead-searches/{id}/repeat', [$out, 'repeatSearch'])->whereNumber('id');
            });
            Route::middleware('perm:marketing')->group(function () {
                $flow = \App\Http\Controllers\Admin\EmailFlowController::class;
                Route::get('flows', [$flow, 'index']);
                Route::get('flows/{id}', [$flow, 'show'])->whereNumber('id');
                Route::put('flows/{id}', [$flow, 'update'])->whereNumber('id');
                $mail = \App\Http\Controllers\Admin\EmailCampaignController::class;
                Route::get('campaigns', [$mail, 'index']);
                Route::post('campaigns', [$mail, 'store']);
                Route::put('campaigns/popup', [$mail, 'popup']);
                Route::get('campaigns/{id}', [$mail, 'show'])->whereNumber('id');
                Route::put('campaigns/{id}', [$mail, 'update'])->whereNumber('id');
                Route::delete('campaigns/{id}', [$mail, 'destroy'])->whereNumber('id');
                Route::get('campaigns/{id}/preview', [$mail, 'preview'])->whereNumber('id');
                Route::post('campaigns/{id}/test', [$mail, 'test'])->whereNumber('id')->middleware('throttle:campaign-test');
                Route::post('campaigns/{id}/send', [$mail, 'send'])->whereNumber('id');
                Route::post('campaigns/{id}/unschedule', [$mail, 'unschedule'])->whereNumber('id');
                $promo = \App\Http\Controllers\Admin\PromotionController::class;
                Route::get('promotions', [$promo, 'index']);
                Route::post('promotions', [$promo, 'store']);
                Route::get('promotions/products', [ProductController::class, 'index']);   // product picker for marketing staff
                Route::get('promotions/{id}', [$promo, 'show'])->whereNumber('id');
                Route::put('promotions/{id}', [$promo, 'update'])->whereNumber('id');
                Route::delete('promotions/{id}', [$promo, 'destroy'])->whereNumber('id');
                $seo = \App\Http\Controllers\Admin\SeoPageController::class;   // SEO landing pages per market + keyword
                Route::get('seo-pages', [$seo, 'index']);
                Route::post('seo-pages', [$seo, 'generate'])->middleware('throttle:20,1');
                Route::post('seo-pages/publish', [$seo, 'publish']);
                Route::get('seo-pages/{id}', [$seo, 'show'])->whereNumber('id');
                Route::put('seo-pages/{id}', [$seo, 'update'])->whereNumber('id');
                Route::delete('seo-pages/{id}', [$seo, 'destroy'])->whereNumber('id');
                $cr = \App\Http\Controllers\Admin\CreatorController::class;   // creators to collaborate with
                Route::get('creators', [$cr, 'index']);
                Route::post('creators/search', [$cr, 'search'])->middleware('throttle:10,1');
                Route::patch('creators/{id}', [$cr, 'update'])->whereNumber('id');
                Route::post('creators/{id}/outreach', [$cr, 'outreach'])->whereNumber('id')->middleware('throttle:30,1');
                Route::post('creators/{id}/affiliate', [$cr, 'affiliate'])->whereNumber('id');
                Route::delete('creators/{id}', [$cr, 'destroy'])->whereNumber('id');
            });
            Route::middleware('perm:marketing')->get('settings/tracking', [\App\Http\Controllers\Admin\TrackingController::class, 'show']);
            Route::middleware('perm:marketing')->put('settings/tracking', [\App\Http\Controllers\Admin\TrackingController::class, 'update']);
            Route::middleware('perm:marketing')->get('loyalty', [\App\Http\Controllers\Admin\LoyaltyController::class, 'show']);
            Route::middleware('perm:marketing')->put('loyalty', [\App\Http\Controllers\Admin\LoyaltyController::class, 'update']);
            Route::middleware('perm:marketing')->put('settings/tracking/server-events', [\App\Http\Controllers\Admin\TrackingController::class, 'updateServerEvents']);
            Route::middleware(['perm:marketing', 'throttle:10,1'])->post('settings/tracking/server-events/test', [\App\Http\Controllers\Admin\TrackingController::class, 'testServerEvents']);
            Route::middleware('perm:marketing')->put('abandoned-checkouts/settings', [\App\Http\Controllers\Admin\AbandonedCheckoutController::class, 'updateSettings']);

            Route::middleware('perm:online_store')->group(function () {
                Route::get('blog-posts', [\App\Http\Controllers\Admin\BlogPostController::class, 'index']);
                Route::post('blog-posts', [\App\Http\Controllers\Admin\BlogPostController::class, 'store']);
                Route::get('blog-posts/{id}', [\App\Http\Controllers\Admin\BlogPostController::class, 'show'])->whereNumber('id');
                Route::put('blog-posts/{id}', [\App\Http\Controllers\Admin\BlogPostController::class, 'update'])->whereNumber('id');
                Route::delete('blog-posts/{id}', [\App\Http\Controllers\Admin\BlogPostController::class, 'destroy'])->whereNumber('id');
                Route::get('pages', [PageController::class, 'index']);
                Route::post('pages', [PageController::class, 'store']);
                Route::post('pages/preview', [PageController::class, 'preview']);
                Route::get('pages/{id}', [PageController::class, 'show'])->whereNumber('id');
                Route::put('pages/{id}', [PageController::class, 'update'])->whereNumber('id');
                Route::delete('pages/{id}', [PageController::class, 'destroy'])->whereNumber('id');
                Route::get('translations', [TranslationController::class, 'index']);
                Route::put('translations/{type}/{id}', [TranslationController::class, 'update'])->whereNumber('id');
                Route::get('redirects', [\App\Http\Controllers\Admin\RedirectController::class, 'index']);
                Route::post('redirects', [\App\Http\Controllers\Admin\RedirectController::class, 'store']);
                Route::post('redirects/import', [\App\Http\Controllers\Admin\RedirectController::class, 'import'])->middleware('throttle:imports');
                Route::get('redirects/export', [\App\Http\Controllers\Admin\RedirectController::class, 'export']);
                Route::post('redirects/delete', [\App\Http\Controllers\Admin\RedirectController::class, 'destroy']);
                Route::put('redirects/{id}', [\App\Http\Controllers\Admin\RedirectController::class, 'update'])->whereNumber('id');
                Route::get('menus', [MenuController::class, 'index']);
                Route::put('menus/{handle}', [MenuController::class, 'update']);
                Route::get('theme', [ThemeController::class, 'show']);
                Route::put('theme', [ThemeController::class, 'update'])->middleware('throttle:theme-save');
                Route::post('theme/publish', [ThemeController::class, 'publish']);
                Route::post('theme/discard', [ThemeController::class, 'discard']);
                Route::get('theme/versions', [ThemeController::class, 'versions']);
                Route::post('theme/generate', [ThemeController::class, 'generate'])->middleware('throttle:10,1');   // automatic design (draft)
                Route::get('theme/check', [ThemeController::class, 'check']);
                Route::get('theme/schedules', [ThemeController::class, 'schedules']);
                Route::post('theme/schedules', [ThemeController::class, 'schedule'])->middleware('throttle:20,1');
                Route::post('theme/schedules/{schedule}/approve', [ThemeController::class, 'approveSchedule'])->whereNumber('schedule');
                Route::post('theme/schedules/{schedule}/cancel', [ThemeController::class, 'cancelSchedule'])->whereNumber('schedule');
                Route::put('theme/auto-campaigns', [ThemeController::class, 'autoCampaigns']);
                Route::post('theme/ai/propose', [ThemeController::class, 'aiPropose'])->middleware('throttle:20,1');   // AI design (proposal → preview → apply)
                Route::post('theme/ai/apply', [ThemeController::class, 'aiApply']);
                Route::post('theme/ai/review', [ThemeController::class, 'aiReview'])->middleware('throttle:20,1');
                Route::post('theme/ai/localize', [ThemeController::class, 'aiLocalize'])->middleware('throttle:10,1');
                Route::post('theme/experiments', [ThemeController::class, 'startExperiment']);   // design A/B test (draft vs live)
                $dp = \App\Http\Controllers\Admin\DesignPerformanceController::class;   // 装修效果
                Route::get('design/performance', [$dp, 'index']);
                Route::get('assistant', [\App\Http\Controllers\Admin\AssistantController::class, 'show']);   // AI 导购
                Route::put('assistant', [\App\Http\Controllers\Admin\AssistantController::class, 'update']);
                Route::post('design/insights/refresh', [$dp, 'refresh'])->middleware('throttle:5,1');
                Route::post('design/experiments/{experiment}/finish', [$dp, 'finish'])->whereNumber('experiment');
                Route::post('theme/versions/{version}/restore', [ThemeController::class, 'restoreVersion'])->whereNumber('version');
                // Theme library: saved themes, copies, export / import (Lumée packages and Shopify themes)
                Route::get('themes', [\App\Http\Controllers\Admin\ThemeLibraryController::class, 'index']);
                Route::post('themes', [\App\Http\Controllers\Admin\ThemeLibraryController::class, 'store']);
                Route::post('themes/import', [\App\Http\Controllers\Admin\ThemeLibraryController::class, 'import'])->middleware('throttle:imports');
                Route::get('themes/market', [\App\Http\Controllers\Admin\ThemeLibraryController::class, 'market']);
                Route::post('themes/market/{id}/install', [\App\Http\Controllers\Admin\ThemeLibraryController::class, 'installListing'])->whereNumber('id')->middleware('throttle:imports');
                Route::patch('themes/{id}', [\App\Http\Controllers\Admin\ThemeLibraryController::class, 'update'])->whereNumber('id');
                Route::delete('themes/{id}', [\App\Http\Controllers\Admin\ThemeLibraryController::class, 'destroy'])->whereNumber('id');
                Route::get('themes/{id}/export', [\App\Http\Controllers\Admin\ThemeLibraryController::class, 'export'])->whereNumber('id');
            });

            Route::middleware('perm:orders')->group(function () {
                Route::get('orders', [OrderController::class, 'index']);
                Route::get('orders/export', [OrderController::class, 'export'])->middleware('throttle:imports');
                Route::get('returns', [\App\Http\Controllers\Admin\ReturnController::class, 'index']);
                Route::get('subscriptions', [\App\Http\Controllers\Admin\SubscriptionController::class, 'index']);
                Route::get('subscriptions/{id}', [\App\Http\Controllers\Admin\SubscriptionController::class, 'show'])->whereNumber('id');
                Route::post('subscriptions/{id}', [\App\Http\Controllers\Admin\SubscriptionController::class, 'update'])->whereNumber('id')->middleware('throttle:30,1');
                Route::put('returns/settings', [\App\Http\Controllers\Admin\ReturnController::class, 'settings']);
                Route::put('returns/{id}', [\App\Http\Controllers\Admin\ReturnController::class, 'update'])->whereNumber('id');
                Route::get('orders/manual/variants', [\App\Http\Controllers\Admin\ManualOrderController::class, 'variants']);
                Route::post('orders/manual', [\App\Http\Controllers\Admin\ManualOrderController::class, 'store'])->middleware('throttle:30,1');
                Route::get('abandoned-checkouts', [\App\Http\Controllers\Admin\AbandonedCheckoutController::class, 'index']);
                Route::get('orders/{order}', [OrderController::class, 'show']);
                Route::post('orders/{order}/mark-paid', [OrderController::class, 'markPaid']);
                Route::post('orders/{order}/fulfill', [OrderController::class, 'fulfill']);
                Route::put('orders/{order}/tracking', [OrderController::class, 'updateTracking']);
                Route::post('orders/{order}/tracking/refresh', [OrderController::class, 'refreshTracking'])->middleware('throttle:30,1');
                Route::post('orders/{order}/refunds', [OrderController::class, 'refund'])->middleware('throttle:refunds');
                Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->middleware('throttle:refunds');
                Route::post('orders/{order}/comments', [OrderController::class, 'comment']);
                Route::post('orders/{order}/edit', [OrderController::class, 'edit'])->middleware('throttle:refunds');
                Route::post('orders/{order}/balance-received', [OrderController::class, 'balanceReceived']);
                Route::post('orders/{order}/downloads/reset', [\App\Http\Controllers\Admin\ProductFileController::class, 'resetDownloads']);
                Route::post('orders/{order}/risk/release', [\App\Http\Controllers\Admin\RiskController::class, 'release']);
                Route::post('orders/{order}/notifications/{type}', [NotificationController::class, 'resend'])->middleware('throttle:email-resend');
            });

            Route::middleware('perm:customers')->group(function () {
                Route::get('customers', [CustomerController::class, 'index']);
                // Lead finder (获客雷达)
                $lf = \App\Http\Controllers\Admin\LeadController::class;
                Route::get('lead-finder/meta', [$lf, 'meta']);
                Route::post('leads/{lead}/catalog', [\App\Http\Controllers\Admin\WholesaleCatalogController::class, 'forLead'])->whereNumber('lead');   // wholesale catalog link for a prospect
                Route::put('lead-finder/settings', [$lf, 'settings'])->middleware('throttle:30,1');
                Route::post('lead-finder/settings/test', [$lf, 'testKey'])->middleware('throttle:10,1');
                Route::get('lead-searches', [$lf, 'searches']);
                Route::post('lead-searches', [$lf, 'createSearch'])->middleware('throttle:20,1');
                Route::post('lead-searches/import', [$lf, 'importSites'])->middleware('throttle:20,1');
                Route::get('lead-searches/{id}', [$lf, 'showSearch'])->whereNumber('id');
                Route::delete('lead-searches/{id}', [$lf, 'destroySearch'])->whereNumber('id');
                Route::get('leads', [$lf, 'index']);
                Route::get('leads/export', [$lf, 'export'])->middleware('throttle:imports');
                Route::get('leads/map', [$lf, 'map']);
                Route::post('lead-finder/plan', [$lf, 'plan'])->middleware('throttle:30,1');
                Route::post('leads/bulk', [$lf, 'bulk']);
                Route::get('leads/{id}', [$lf, 'show'])->whereNumber('id');
                Route::patch('leads/{id}', [$lf, 'update'])->whereNumber('id');
                Route::post('leads/{id}/activities', [$lf, 'addActivity'])->whereNumber('id');
                Route::post('leads/{id}/recheck', [$lf, 'recheck'])->whereNumber('id')->middleware('throttle:60,1');
                Route::post('leads/{id}/analyze', [$lf, 'analyze'])->whereNumber('id')->middleware('throttle:60,1');
                Route::post('leads/{id}/outreach', [$lf, 'outreach'])->whereNumber('id')->middleware('throttle:60,1');
                Route::post('leads/{id}/convert', [$lf, 'convert'])->whereNumber('id');
                Route::get('customers/tags', [CustomerController::class, 'tags']);
                Route::get('customers/export', [CustomerController::class, 'export'])->middleware('throttle:imports');
                Route::post('customers/bulk-tags', [CustomerController::class, 'bulkTags']);
                Route::get('customers/{id}', [CustomerController::class, 'show'])->whereNumber('id');
                Route::patch('customers/{id}', [CustomerController::class, 'update'])->whereNumber('id');
                $co = \App\Http\Controllers\Admin\CompanyController::class;
                Route::get('companies', [$co, 'index']);
                Route::post('companies', [$co, 'store']);
                Route::put('b2b/settings', [$co, 'settings']);
                Route::get('b2b/applications', [$co, 'applications']);
                Route::post('b2b/applications/{id}/approve', [$co, 'approve'])->whereNumber('id');
                Route::post('b2b/applications/{id}/reject', [$co, 'reject'])->whereNumber('id');
                Route::get('companies/{id}', [$co, 'show'])->whereNumber('id');
                Route::put('companies/{id}', [$co, 'update'])->whereNumber('id');
                Route::delete('companies/{id}', [$co, 'destroy'])->whereNumber('id');
                Route::post('companies/{id}/members', [$co, 'addMember'])->whereNumber('id');
                Route::delete('companies/{id}/members/{customerId}', [$co, 'removeMember'])->whereNumber('id')->whereNumber('customerId');
                $qc = \App\Http\Controllers\Admin\QuoteController::class;
                Route::get('quotes', [$qc, 'index']);
                Route::get('quotes/{id}', [$qc, 'show'])->whereNumber('id');
                Route::post('quotes/{id}/send', [$qc, 'send'])->whereNumber('id');
                Route::post('quotes/{id}/decline', [$qc, 'decline'])->whereNumber('id');
                Route::get('customers/{id}/points', [\App\Http\Controllers\Admin\LoyaltyController::class, 'customer'])->whereNumber('id');
                Route::post('customers/{id}/points', [\App\Http\Controllers\Admin\LoyaltyController::class, 'adjust'])->whereNumber('id');
            });

            Route::middleware('perm:settings')->group(function () {
                Route::get('settings/sms', [\App\Http\Controllers\Admin\SmsSettingsController::class, 'show']);
                Route::put('settings/sms', [\App\Http\Controllers\Admin\SmsSettingsController::class, 'update']);
                Route::post('settings/sms/test', [\App\Http\Controllers\Admin\SmsSettingsController::class, 'test'])->middleware('throttle:5,1');
                Route::get('activity', [\App\Http\Controllers\Admin\ActivityLogController::class, 'index']);
                Route::get('settings/store', [StoreSettingsController::class, 'show']);
                Route::put('settings/store', [StoreSettingsController::class, 'update']);
                Route::get('domains', [DomainController::class, 'index']);
                Route::post('domains', [DomainController::class, 'store'])->middleware('throttle:domains');
                Route::post('domains/{id}/verify', [DomainController::class, 'verify'])->whereNumber('id')->middleware('throttle:domain-verify');
                Route::post('domains/{id}/primary', [DomainController::class, 'makePrimary'])->whereNumber('id');
                Route::delete('domains/{id}', [DomainController::class, 'destroy'])->whereNumber('id');
                Route::get('settings/shipping', [ShippingController::class, 'show']);
                Route::get('settings/pickup', [\App\Http\Controllers\Admin\ProductFileController::class, 'pickup']);
                Route::put('settings/pickup', [\App\Http\Controllers\Admin\ProductFileController::class, 'savePickup']);
                Route::put('settings/shipping', [ShippingController::class, 'update']);
                Route::get('settings/currencies', [CurrencySettingsController::class, 'show']);
                Route::put('settings/currencies', [CurrencySettingsController::class, 'update']);
                Route::post('settings/currencies/refresh', [CurrencySettingsController::class, 'refreshRates'])->middleware('throttle:domains');
                $mk = \App\Http\Controllers\Admin\MarketController::class;   // markets (country sites)
                Route::get('markets', [$mk, 'index']);
                Route::post('markets', [$mk, 'store']);
                Route::put('markets/{market}', [$mk, 'update']);
                Route::delete('markets/{market}', [$mk, 'destroy']);
                Route::put('markets/{market}/localization', [$mk, 'saveLocalization']);
                Route::post('markets/{market}/preset', [$mk, 'applyPreset']);
                Route::get('markets/{market}/calendar', [$mk, 'calendar'])->whereNumber('market');
                Route::get('settings/languages', [TranslationController::class, 'languages']);
                Route::put('settings/languages', [TranslationController::class, 'updateLanguages']);
                $af = \App\Http\Controllers\Admin\AffiliateController::class;
                Route::get('affiliates', [$af, 'index']);
                Route::post('affiliates', [$af, 'store'])->middleware('throttle:30,1');
                Route::put('affiliates/settings', [$af, 'settings']);
                Route::put('affiliates/{id}', [$af, 'update'])->whereNumber('id');
                Route::delete('affiliates/{id}', [$af, 'destroy'])->whereNumber('id');
                Route::post('affiliates/{id}/pay', [$af, 'pay'])->whereNumber('id');
                Route::get('affiliates/{id}/commissions', [$af, 'commissions'])->whereNumber('id');
                $pv = \App\Http\Controllers\Admin\PrivacyController::class;
                Route::get('privacy-requests', [$pv, 'index']);
                Route::post('privacy-requests/{id}/complete', [$pv, 'complete'])->whereNumber('id');
                Route::post('privacy-requests/{id}/reject', [$pv, 'reject'])->whereNumber('id');
                Route::get('customers/{id}/data', [$pv, 'export'])->whereNumber('id')->middleware('throttle:20,1');
                Route::post('customers/{id}/erase', [$pv, 'erase'])->whereNumber('id');
                Route::get('settings/risk', [\App\Http\Controllers\Admin\RiskController::class, 'show']);
                Route::put('settings/risk', [\App\Http\Controllers\Admin\RiskController::class, 'update']);
                Route::post('settings/risk/blocklist', [\App\Http\Controllers\Admin\RiskController::class, 'block'])->middleware('throttle:60,1');
                Route::delete('settings/risk/blocklist/{id}', [\App\Http\Controllers\Admin\RiskController::class, 'unblock'])->whereNumber('id');
                Route::get('settings/taxes', [TaxController::class, 'show']);
                Route::put('settings/taxes', [TaxController::class, 'update']);
                Route::get('settings/payments', [PaymentSettingsController::class, 'show']);
                Route::put('settings/payments', [PaymentSettingsController::class, 'update']);
                Route::post('settings/payments/stripe/connect', [PaymentSettingsController::class, 'stripeConnect'])->middleware('throttle:20,1');
                Route::post('settings/payments/stripe/refresh', [PaymentSettingsController::class, 'stripeRefresh'])->middleware('throttle:30,1');
                Route::post('settings/payments/stripe/dashboard', [PaymentSettingsController::class, 'stripeDashboard'])->middleware('throttle:20,1');
                Route::delete('settings/payments/stripe', [PaymentSettingsController::class, 'stripeDisconnect']);
                $apps = \App\Http\Controllers\Admin\AppController::class;
                Route::get('apps', [$apps, 'index']);
                Route::get('apps/charges/{token}', [$apps, 'chargeInfo'])->where('token', '[A-Za-z0-9]{40}');
                Route::post('apps/charges/{token}/approve', [$apps, 'approveCharge'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:30,1');
                Route::post('apps/charges/{token}/decline', [$apps, 'declineCharge'])->where('token', '[A-Za-z0-9]{40}');
                Route::post('apps/installations/{id}/session', [$apps, 'session'])->whereNumber('id')->middleware('throttle:120,1');
                Route::get('apps/{id}/reviews', [$apps, 'reviews'])->whereNumber('id');
                Route::put('apps/{id}/review', [$apps, 'saveReview'])->whereNumber('id')->middleware('throttle:20,1');
                Route::delete('apps/{id}/review', [$apps, 'deleteReview'])->whereNumber('id');
                Route::get('oauth/authorize', [$apps, 'authorizeInfo']);
                Route::post('oauth/authorize', [$apps, 'approve'])->middleware('throttle:30,1');
                Route::delete('apps/installations/{id}', [$apps, 'uninstall'])->whereNumber('id');
                $dev = \App\Http\Controllers\Admin\DeveloperController::class;
                Route::get('developers', [$dev, 'index']);
                Route::post('developers/tokens', [$dev, 'createToken'])->middleware('throttle:20,1');
                Route::delete('developers/tokens/{id}', [$dev, 'revokeToken'])->whereNumber('id');
                Route::post('developers/webhooks', [$dev, 'createWebhook'])->middleware('throttle:20,1');
                Route::put('developers/webhooks/{id}', [$dev, 'updateWebhook'])->whereNumber('id');
                Route::delete('developers/webhooks/{id}', [$dev, 'deleteWebhook'])->whereNumber('id');
                Route::get('developers/webhooks/{id}/secret', [$dev, 'secret'])->whereNumber('id');
                Route::post('developers/webhooks/{id}/test', [$dev, 'test'])->whereNumber('id')->middleware('throttle:20,1');
                Route::get('developers/webhooks/{id}/deliveries', [$dev, 'deliveries'])->whereNumber('id');
                Route::post('developers/deliveries/{id}/redeliver', [$dev, 'redeliver'])->whereNumber('id')->middleware('throttle:20,1');
                Route::get('settings/notifications', [NotificationController::class, 'show']);
                Route::put('settings/notifications', [NotificationController::class, 'update']);
                Route::get('notifications/{type}/preview', [NotificationController::class, 'preview']);
                Route::post('notifications/{type}/test', [NotificationController::class, 'test'])->middleware('throttle:email-test');
            });

            // Staff management: store owner only
            Route::middleware('perm:owner')->group(function () {
                Route::get('staff', [StaffController::class, 'index']);
                Route::post('staff/invitations', [StaffController::class, 'invite'])->middleware('throttle:staff-invite');
                Route::post('staff/invitations/{id}/resend', [StaffController::class, 'resend'])->whereNumber('id')->middleware('throttle:staff-invite');
                Route::delete('staff/invitations/{id}', [StaffController::class, 'cancel'])->whereNumber('id');
                Route::put('staff/{userId}', [StaffController::class, 'update'])->whereNumber('userId');
                Route::delete('staff/{userId}', [StaffController::class, 'remove'])->whereNumber('userId');
            });
            });   // billing.open
        });
    });
});

/*
| Ops console — Lumée platform staff only.
*/
/*
| Open API v1 — store API tokens ("Bearer lm_…", created under Settings → Developers).
| Same controllers and validation as the admin, scoped by the token's store; see docs/api.md.
*/
Route::post('oauth/token', [\App\Http\Controllers\Api\OAuthController::class, 'token'])->middleware('throttle:60,1');

Route::prefix('v1')->middleware(['api.token', 'throttle:api-token'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\InventoryController::class, 'me']);
    Route::get('webhooks', [\App\Http\Controllers\Api\WebhookController::class, 'index']);
    $charges = \App\Http\Controllers\Api\AppChargeController::class;
    Route::get('app_charges', [$charges, 'index']);
    Route::post('app_charges', [$charges, 'store']);
    Route::get('app_charges/{id}', [$charges, 'show'])->whereNumber('id');
    Route::delete('app_charges/{id}', [$charges, 'destroy'])->whereNumber('id');
    Route::post('webhooks', [\App\Http\Controllers\Api\WebhookController::class, 'store']);
    Route::delete('webhooks/{id}', [\App\Http\Controllers\Api\WebhookController::class, 'destroy'])->whereNumber('id');

    Route::middleware('api.scope:read_products')->group(function () {
        Route::get('products', [ProductController::class, 'index']);
        Route::get('products/{product}', [ProductController::class, 'show']);
        Route::get('collections', [CollectionController::class, 'index']);
    });
    Route::middleware('api.scope:write_products')->group(function () {
        Route::post('products', [ProductController::class, 'store']);
        Route::match(['put', 'patch'], 'products/{product}', [ProductController::class, 'update']);
        Route::delete('products/{product}', [ProductController::class, 'destroy']);
    });
    Route::middleware('api.scope:write_inventory')->post('inventory/adjust', [\App\Http\Controllers\Api\InventoryController::class, 'adjust']);

    Route::middleware('api.scope:read_orders')->group(function () {
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{order}', [OrderController::class, 'show']);
    });
    Route::middleware('api.scope:write_orders')->group(function () {
        Route::post('orders/{order}/fulfill', [OrderController::class, 'fulfill']);
        Route::put('orders/{order}/tracking', [OrderController::class, 'updateTracking']);
    });

    Route::middleware('api.scope:read_customers')->group(function () {
        Route::get('customers', [CustomerController::class, 'index']);
        Route::get('customers/{id}', [\App\Http\Controllers\Api\InventoryController::class, 'customer'])->whereNumber('id');
    });
});

Route::prefix('ops')->middleware(['auth:sanctum', 'platform.admin'])->group(function () {
    Route::get('apps', [\App\Http\Controllers\Ops\AppReviewController::class, 'index']);
    Route::post('apps/{id}/review', [\App\Http\Controllers\Ops\AppReviewController::class, 'review'])->whereNumber('id');
    Route::get('payouts', [\App\Http\Controllers\Ops\PayoutController::class, 'index']);
    Route::post('payouts/{id}/paid', [\App\Http\Controllers\Ops\PayoutController::class, 'markPaid'])->whereNumber('id');
    Route::get('themes', [\App\Http\Controllers\Ops\ThemeReviewController::class, 'index']);
    Route::post('themes/{id}/review', [\App\Http\Controllers\Ops\ThemeReviewController::class, 'review'])->whereNumber('id');
    Route::get('themes/{id}/package', [\App\Http\Controllers\Ops\ThemeReviewController::class, 'download'])->whereNumber('id');
    Route::get('overview', [OpsController::class, 'overview']);
    Route::get('stores', [OpsController::class, 'stores']);
    Route::get('stores/{id}', [OpsController::class, 'store'])->whereNumber('id');
    Route::post('stores/{id}/extend-trial', [OpsController::class, 'extendTrial'])->whereNumber('id');
    Route::post('stores/{id}/grant', [OpsController::class, 'grant'])->whereNumber('id');
    Route::post('stores/{id}/suspend', [OpsController::class, 'suspend'])->whereNumber('id');
    Route::post('stores/{id}/unsuspend', [OpsController::class, 'unsuspend'])->whereNumber('id');
    Route::get('invoices', [OpsController::class, 'invoices']);
    Route::post('invoices/{id}/mark-paid', [OpsController::class, 'markInvoicePaid'])->whereNumber('id');
});
