<?php

namespace App\Markets;

use App\I18n\Localization;
use App\Models\Market;
use App\Models\Store;
use App\Support\Countries;

/**
 * What the storefront layout needs about markets: hreflang alternates, the language and
 * country/region pickers, and the "shop in your country's store?" suggestion.
 * $path is the page path without any language/market prefix ("/products/rose-ring").
 */
class Storefront
{
    /** Live markets of the store, once per request. */
    private static function markets(Store $store): \Illuminate\Support\Collection
    {
        $key = 'lumee_markets_'.$store->id;
        if (! request()->attributes->has($key)) {
            request()->attributes->set($key, MarketContext::live($store));
        }

        return request()->attributes->get($key);
    }

    private static function url(Store $store, string $prefix, string $path): string
    {
        return $store->storefrontUrl().($prefix === '' ? $path : $prefix.($path === '/' ? '' : $path));
    }

    /**
     * The same page in every language of every market, for <link rel="alternate" hreflang>.
     * Primary market languages are plain ("fr"); markets are language + country ("es-MX").
     *
     * @return array<int, array{hreflang: string, href: string}>
     */
    public static function alternates(Store $store, string $path): array
    {
        $out = [];
        foreach (Localization::enabled($store) as $i => $code) {
            $out[] = ['hreflang' => $code, 'href' => self::url($store, $i === 0 ? '' : '/'.$code, $path)];
        }
        foreach (self::markets($store) as $m) {
            foreach ($m->languages() as $code) {
                foreach ((array) $m->countries as $country) {
                    $out[] = ['hreflang' => $code.'-'.strtoupper($country), 'href' => self::url($store, $m->prefix($code), $path)];
                }
            }
        }
        if (count($out) > 1) {
            $out[] = ['hreflang' => 'x-default', 'href' => self::url($store, '', $path)];
        }

        return count($out) > 1 ? $out : [];
    }

    /** Languages for the language picker: the current market's, else the store's published ones. */
    public static function languages(Store $store): array
    {
        return app(MarketContext::class)->market()?->languages() ?? Localization::enabled($store);
    }

    /**
     * Country/region picker: the store's own site plus every live market.
     *
     * @return array<int, array{label: string, href: string, current: bool}>
     */
    public static function regions(Store $store, string $path): array
    {
        $markets = self::markets($store);
        if ($markets->isEmpty()) {
            return [];
        }
        $current = app(MarketContext::class)->market()?->id;
        $out = [[
            'label' => __('International').' · '.$store->baseCurrency(),
            'href' => self::url($store, '', $path),
            'current' => $current === null,
        ]];
        foreach ($markets as $m) {
            $out[] = ['label' => $m->name.' · '.$m->currency, 'href' => self::url($store, $m->prefix(), $path), 'current' => $current === $m->id];
        }

        return $out;
    }

    /**
     * A shopper on the store's own site whose country has its own market: suggest it (never redirect,
     * so shoppers and search engines can always see every site). Country from the CDN header.
     *
     * @return array{market: Market, country: string, href: string}|null
     */
    public static function suggestion(Store $store, string $path): ?array
    {
        if (app(MarketContext::class)->active() || request()->cookie('lumee_market_choice') || request()->isMethodSafe() === false) {
            return null;
        }
        $country = strtoupper((string) request()->header('CF-IPCountry'));
        if (strlen($country) !== 2) {
            return null;
        }
        $market = self::markets($store)->first(fn (Market $m) => in_array($country, array_map('strtoupper', (array) $m->countries), true));

        return $market ? ['market' => $market, 'country' => Countries::all()[$country] ?? $country, 'href' => self::url($store, $market->prefix(), $path)] : null;
    }
}
