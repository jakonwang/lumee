<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Marketing\SeoPages;
use App\Markets\MarketContext;
use App\Models\SeoPage;
use App\Tenancy\CurrentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** SEO landing page: {site prefix}/s/{slug}. Each page belongs to one site (market + language); other sites redirect there. */
class SeoPageController extends Controller
{
    public function show(CurrentStore $current, SeoPages $pages, string $slug): View|RedirectResponse
    {
        $page = SeoPage::published()->where('slug', $slug)->firstOrFail();
        $store = $current->getOrFail();
        if ($page->market_id !== app(MarketContext::class)->market()?->id || $page->locale !== app()->getLocale()) {
            return redirect()->away(SeoPages::url($store, $page), 301);
        }
        $products = $pages->products($page);

        return view($current->themeView('seo-landing'), compact('page', 'products'));
    }
}
