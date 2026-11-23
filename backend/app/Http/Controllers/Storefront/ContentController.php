<?php

namespace App\Http\Controllers\Storefront;

use App\Analytics\Visits;
use App\Catalog\CollectionFilters;
use App\Currency\CurrencySettings;
use App\Currency\Presentment;
use App\Http\Controllers\Controller;
use App\I18n\Localization;
use App\Markets\MarketContext;
use App\Models\BlogPost;
use App\Models\Collection;
use App\Models\MarketProductExclusion;
use App\Models\Page;
use App\Models\Product;
use App\Models\Store;
use App\Storefront\PageCache;
use App\Tenancy\CurrentStore;
use App\Themes\ThemeContext;
use App\Themes\ThemeService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/** Content pages, product search, and the files search engines read (sitemap.xml, robots.txt). */
class ContentController extends Controller
{
    public function page(CurrentStore $current, string $handle): View
    {
        $page = Page::published()->where('handle', $handle)->firstOrFail();
        if ($page->layout === 'blocks') {
            // Campaign landing page: its blocks from the theme (defaults until it's designed)
            $theme = app(ThemeContext::class);
            $blocks = $theme->pageSections($page->id) ?? app(ThemeService::class)->pageDefaults($theme->theme, $page->id);

            return view($current->themeView('landing'), compact('page', 'blocks'));
        }

        return view($current->themeView('page'), compact('page'));
    }

    public function search(Request $request, CurrentStore $current): View
    {
        $q = mb_substr(trim((string) $request->query('q')), 0, 100);
        $products = null;
        $filters = ['groups' => [], 'price' => null, 'active' => [], 'any' => false];

        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $products = Product::active()
                ->where(fn ($w) => $w->where('title', 'like', $like)
                    ->orWhere('vendor', 'like', $like)
                    ->orWhere('product_type', 'like', $like)
                    ->orWhere('tags', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhereHas('variants', fn ($v) => $v->where('sku', 'like', $like)))
                // Title matches first, then newest
                ->orderByRaw('CASE WHEN title LIKE ? THEN 0 ELSE 1 END', [$like])
                ->latest('id');
            $filters = app(CollectionFilters::class)->apply($products, $request);   // same filters as collections
            $products = $products
                ->with('variants:id,product_id,price,compare_at_price,inventory_quantity,position')
                ->paginate(24)
                ->withQueryString();
            if ((int) $request->query('page', 1) <= 1 && ! $filters['any']) {
                app(Visits::class)->search($q, $products->total());   // what shoppers look for (and don't find)
            }
        }

        $clearFilters = CollectionFilters::clearUrl($request);

        return view($current->themeView('search'), compact('q', 'products', 'filters', 'clearFilters'));
    }

    /** Remember the shopper's display currency for a year (only currencies the store offers). */
    public function currency(Request $request, CurrentStore $current): RedirectResponse
    {
        $code = (string) $request->input('currency');
        $back = redirect()->back(fallback: '/');
        if (! in_array($code, CurrencySettings::for($current->getOrFail())->offered(), true)) {
            return $back;
        }

        return $back->withCookie(cookie()->make(Presentment::COOKIE, $code, 60 * 24 * 365, null, null, null, true, false, 'lax'));
    }

    public function sitemap(Request $request, CurrentStore $current): Response
    {
        $store = $current->getOrFail();
        $base = $store->storefrontUrl();
        // Built once per store version (saving products, pages, markets… clears it), kept an hour
        $urls = Cache::remember('sitemap:'.$store->id.':'.PageCache::version($store->id), 3600,
            fn () => $this->sitemapUrls($store, $base));
        // The sitemap protocol allows 50,000 URLs per file: bigger sites get an index of pages (?page=N)
        $per = max(1, (int) config('lumee.sitemap_page_size', 40000));
        $pages = max(1, (int) ceil(count($urls) / $per));
        $page = (int) $request->query('page', 0);

        if ($pages > 1 && $page === 0) {
            $xml = '<?xml version="1.0" encoding="UTF-8"?>'.'
'.'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.'
';
            for ($n = 1; $n <= $pages; $n++) {
                $xml .= '  <sitemap><loc>'.htmlspecialchars($base.'/sitemap.xml?page='.$n, ENT_XML1).'</loc></sitemap>'.'
';
            }

            return response($xml.'</sitemapindex>'.'
', 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
        }
        abort_if($page > $pages || $page < 0, 404);
        $urls = array_slice($urls, (max(1, $page) - 1) * $per, $per);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $u) {
            $xml .= '  <url><loc>'.htmlspecialchars($u['loc'], ENT_XML1).'</loc>'
                .($u['lastmod'] ? '<lastmod>'.$u['lastmod']->toAtomString().'</lastmod>' : '')."</url>\n";
        }
        $xml .= '</urlset>'."\n";

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /** Per store: keep cart/checkout/account out of search engines and point to the sitemap. */
    public function robots(CurrentStore $current): Response
    {
        if (! $current->check()) {
            return response("User-agent: *\nDisallow:\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $lines = [
            'User-agent: *',
            'Disallow: /cart',
            'Disallow: /checkout',
            'Disallow: /account',
            'Disallow: /orders/',
            'Disallow: /search',
            'Disallow: /*?_preview=',
            '',
            'Sitemap: '.$current->getOrFail()->storefrontUrl().'/sitemap.xml',
        ];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** @return list<array{loc: string, lastmod: ?CarbonInterface}> */
    private function sitemapUrls(Store $store, string $base): array
    {
        $urls = [['loc' => $base.'/', 'lastmod' => $store->updated_at], ['loc' => $base.'/collections/all', 'lastmod' => null]];

        foreach (Collection::published()->get(['handle', 'updated_at']) as $c) {
            $urls[] = ['loc' => $base.'/collections/'.rawurlencode($c->handle), 'lastmod' => $c->updated_at];
        }
        foreach (Product::active()->latest('updated_at')->limit(10000)->get(['id', 'handle', 'updated_at']) as $p) {
            $urls[] = ['loc' => $base.'/products/'.rawurlencode($p->handle), 'lastmod' => $p->updated_at, 'product_id' => $p->id];
        }
        foreach (Page::published()->get(['handle', 'updated_at']) as $p) {
            $urls[] = ['loc' => $base.'/pages/'.rawurlencode($p->handle), 'lastmod' => $p->updated_at];
        }
        $posts = BlogPost::live()->latest('published_at')->limit(5000)->get(['handle', 'updated_at']);
        if ($posts->isNotEmpty()) {
            $urls[] = ['loc' => $base.'/blog', 'lastmod' => $posts->max('updated_at')];
            foreach ($posts as $p) {
                $urls[] = ['loc' => $base.'/blog/'.rawurlencode($p->handle), 'lastmod' => $p->updated_at];
            }
        }

        // Every page again under each extra published language ("/fr/products/…")
        $originals = $urls;
        foreach (array_slice(Localization::enabled($store), 1) as $code) {
            foreach ($originals as $u) {
                $path = substr($u['loc'], strlen($base));
                $urls[] = ['loc' => $base.'/'.$code.($path === '/' ? '' : $path), 'lastmod' => $u['lastmod']];
            }
        }

        // …and under each market and its languages ("/es-mx/products/…"), minus products a market doesn't sell
        foreach (MarketContext::live($store) as $m) {
            $excluded = MarketProductExclusion::where('market_id', $m->id)->pluck('product_id')->flip();
            foreach ($m->languages() as $code) {
                foreach ($originals as $u) {
                    if (isset($u['product_id']) && $excluded->has($u['product_id'])) {
                        continue;
                    }
                    $path = substr($u['loc'], strlen($base));
                    $urls[] = ['loc' => $base.$m->prefix($code).($path === '/' ? '' : $path), 'lastmod' => $u['lastmod']];
                }
            }
        }

        // SEO landing pages: each lives on one site (its market and language) only
        foreach (\App\Models\SeoPage::published()->get() as $sp) {
            $urls[] = ['loc' => \App\Marketing\SeoPages::url($store, $sp), 'lastmod' => $sp->updated_at];
        }

        return array_map(fn ($u) => ['loc' => $u['loc'], 'lastmod' => $u['lastmod']], $urls);
    }
}
