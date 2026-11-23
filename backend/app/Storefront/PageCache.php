<?php

namespace App\Storefront;

use Illuminate\Support\Facades\Cache;

/**
 * Storefront page cache (see CacheStorefrontPage). Pages are kept for a few minutes per store "version"; saving a
 * product, collection, theme, market… bumps the version so shoppers see the change right away.
 *
 * Request-scoped: a controller can say this page must not be cached (skip) or what a cache hit has to replay
 * (a product view, so analytics and the funnel still count it).
 */
class PageCache
{
    public bool $skip = false;

    public ?int $productId = null;

    public static function enabled(): bool
    {
        return (bool) config('lumee.page_cache.enabled');
    }

    public static function version(int $storeId): string
    {
        return (string) Cache::get("page-ver:{$storeId}", '0');
    }

    /** Something shoppers see changed: drop this store's cached pages. */
    public static function bump(?int $storeId): void
    {
        if ($storeId && self::enabled()) {
            Cache::forever("page-ver:{$storeId}", (string) hrtime(true));
        }
    }

    /** This page shows per-visitor content (an A/B test of the product, …). */
    public function skip(): void
    {
        $this->skip = true;
    }

    public function productView(int $id): void
    {
        $this->productId = $id;
    }
}
