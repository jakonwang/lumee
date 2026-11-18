<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Page;
use App\Models\Product;
use App\Tenancy\CurrentStore;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Content pages, product search, and the files search engines read (sitemap.xml, robots.txt). */
class ContentController extends Controller
{
    public function page(CurrentStore $current, string $handle): View
    {
        $page = Page::published()->where('handle', $handle)->firstOrFail();

        return view($current->themeView('page'), compact('page'));
    }

    public function search(Request $request, CurrentStore $current): View
    {
        $q = mb_substr(trim((string) $request->query('q')), 0, 100);
        $products = null;

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
                ->latest('id')
                ->with('variants:id,product_id,price,compare_at_price,inventory_quantity,position')
                ->paginate(24)
                ->withQueryString();
        }

        return view($current->themeView('search'), compact('q', 'products'));
    }

    /** Remember the shopper's display currency for a year (only currencies the store offers). */
    public function currency(Request $request, CurrentStore $current): \Illuminate\Http\RedirectResponse
    {
        $code = (string) $request->input('currency');
        $back = redirect()->back(fallback: '/');
        if (! in_array($code, \App\Currency\CurrencySettings::for($current->getOrFail())->offered(), true)) {
            return $back;
        }

        return $back->withCookie(cookie()->make(\App\Currency\Presentment::COOKIE, $code, 60 * 24 * 365, null, null, null, true, false, 'lax'));
    }

    public function sitemap(CurrentStore $current): Response
    {
        $store = $current->getOrFail();
        $base = $store->storefrontUrl();
        $urls = [['loc' => $base.'/', 'lastmod' => $store->updated_at], ['loc' => $base.'/collections/all', 'lastmod' => null]];

        foreach (Collection::published()->get(['handle', 'updated_at']) as $c) {
            $urls[] = ['loc' => $base.'/collections/'.rawurlencode($c->handle), 'lastmod' => $c->updated_at];
        }
        foreach (Product::active()->latest('updated_at')->limit(10000)->get(['handle', 'updated_at']) as $p) {
            $urls[] = ['loc' => $base.'/products/'.rawurlencode($p->handle), 'lastmod' => $p->updated_at];
        }
        foreach (Page::published()->get(['handle', 'updated_at']) as $p) {
            $urls[] = ['loc' => $base.'/pages/'.rawurlencode($p->handle), 'lastmod' => $p->updated_at];
        }
        $posts = \App\Models\BlogPost::live()->latest('published_at')->limit(5000)->get(['handle', 'updated_at']);
        if ($posts->isNotEmpty()) {
            $urls[] = ['loc' => $base.'/blog', 'lastmod' => $posts->max('updated_at')];
            foreach ($posts as $p) {
                $urls[] = ['loc' => $base.'/blog/'.rawurlencode($p->handle), 'lastmod' => $p->updated_at];
            }
        }

        // Every page again under each extra published language ("/fr/products/…")
        $originals = $urls;
        foreach (array_slice(\App\I18n\Localization::enabled($store), 1) as $code) {
            foreach ($originals as $u) {
                $path = substr($u['loc'], strlen($base));
                $urls[] = ['loc' => $base.'/'.$code.($path === '/' ? '' : $path), 'lastmod' => $u['lastmod']];
            }
        }

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
}
