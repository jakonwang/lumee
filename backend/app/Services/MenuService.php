<?php

namespace App\Services;

use App\I18n\Localization;
use App\Markets\MarketContext;
use App\Models\Collection;
use App\Models\Menu;
use App\Models\Page;
use App\Models\Product;
use Illuminate\Support\Str;

/**
 * Navigation menus. Item shape:
 *   {id, title, type: home|all_products|collections|collection|product|url, target_id?, url?, children: [...]}
 * At most two levels (top items + one level of dropdown children).
 */
class MenuService
{
    public const TYPES = [
        'home' => '首页',
        'all_products' => '全部商品',
        'collections' => '全部分类',
        'collection' => '某个分类',
        'product' => '某个商品',
        'page' => '某个页面',
        'search' => '搜索',
        'blog' => '博客',
        'url' => '自定义链接',
    ];

    public const MAX_ITEMS = 60;

    public function defaults(string $handle): array
    {
        return match ($handle) {
            'main' => [
                $this->item('Home', 'home'),
                $this->item('Shop All', 'all_products'),
                $this->item('Collections', 'collections'),
            ],
            'footer' => [
                $this->item('Shop All', 'all_products'),
                $this->item('Collections', 'collections'),
            ],
            default => [],
        };
    }

    /** The store's menu, created with defaults on first use. */
    public function get(string $handle): Menu
    {
        return Menu::firstOrCreate(
            ['handle' => $handle],
            ['title' => Menu::HANDLES[$handle] ?? $handle, 'items' => $this->defaults($handle)],   // stored in Chinese; shown via __()
        );
    }

    /** Clean untrusted editor input: known types only, safe URLs, max depth 2, targets in this store. */
    public function sanitize(array $items): array
    {
        $count = 0;
        $collectionIds = Collection::pluck('id')->all();   // store-scoped
        $productIds = null;                                 // loaded lazily
        $pageIds = null;

        $clean = function (array $list, int $depth) use (&$clean, &$count, $collectionIds, &$productIds, &$pageIds) {
            $out = [];
            foreach ($list as $raw) {
                if (! is_array($raw) || $count >= self::MAX_ITEMS) {
                    continue;
                }
                $type = $raw['type'] ?? null;
                $title = Str::limit(trim(preg_replace('/\p{C}/u', '', (string) ($raw['title'] ?? ''))), 60, '');
                if (! array_key_exists((string) $type, self::TYPES) || $title === '') {
                    continue;
                }

                $item = ['id' => $this->safeId($raw['id'] ?? null), 'title' => $title, 'type' => $type];

                if (in_array($type, ['collection', 'product', 'page'], true)) {
                    $id = (int) ($raw['target_id'] ?? 0);
                    if ($type === 'product') {
                        $productIds ??= Product::pluck('id')->all();
                    }
                    if ($type === 'page') {
                        $pageIds ??= Page::pluck('id')->all();
                    }
                    $allowed = match ($type) {
                        'collection' => $collectionIds, 'product' => $productIds, 'page' => $pageIds
                    };
                    if (! in_array($id, $allowed, true)) {
                        continue;   // unknown or another store's record
                    }
                    $item['target_id'] = $id;
                }
                if ($type === 'url') {
                    $url = trim((string) ($raw['url'] ?? ''));
                    if (! preg_match('~^(/(?!/)|#|https?://|mailto:)~i', $url) || preg_match('/[\s<>"\']/', $url)) {
                        continue;   // no javascript:, data:, protocol-relative //evil.com, etc.
                    }
                    $item['url'] = Str::limit($url, 500, '');
                }

                $count++;
                // Only one level of nesting; deeper children are lifted to the second level
                $children = is_array($raw['children'] ?? null) ? $raw['children'] : [];
                $item['children'] = $depth === 0 ? $clean($this->flatten($children), 1) : [];
                $out[] = $item;
            }

            return $out;
        };

        return $clean(array_values($items), 0);
    }

    /**
     * Menu items with resolved hrefs for rendering. Links whose target was deleted or
     * unpublished are dropped so shoppers never hit a 404 from the menu.
     *
     * @return array<int, array{title:string, href:string, children:array}>
     */
    public function resolve(string $handle): array
    {
        // Once per request and language: a child theme's layout extends its parent's, so both are composed
        $key = $handle.'|'.app()->getLocale().'|'.app(MarketContext::class)->market()?->id;

        return $this->resolved[$key] ??= $this->build($handle);
    }

    /** @var array<string, array> */
    private array $resolved = [];

    private function build(string $handle): array
    {
        // Read-only on the storefront: fall back to defaults without creating a row
        $menu = Menu::where('handle', $handle)->first();
        $items = $menu?->items ?? $this->defaults($handle);
        $l10n = app(Localization::class);
        $ids = ['collection' => [], 'product' => [], 'page' => []];
        $walk = function ($list) use (&$walk, &$ids) {
            foreach ($list as $i) {
                if (isset($i['target_id'], $ids[$i['type']])) {
                    $ids[$i['type']][] = $i['target_id'];
                }
                $walk($i['children'] ?? []);
            }
        };
        $walk($items);

        $collections = $ids['collection'] ? Collection::published()->whereIn('id', $ids['collection'])->pluck('handle', 'id') : collect();
        $products = $ids['product'] ? Product::active()->whereIn('id', $ids['product'])->pluck('handle', 'id') : collect();
        $pages = $ids['page'] ? Page::published()->whereIn('id', $ids['page'])->pluck('handle', 'id') : collect();

        $build = function ($list) use (&$build, $collections, $products, $pages, $menu, $l10n) {
            $out = [];
            foreach ($list as $i) {
                $href = match ($i['type']) {
                    'home' => '/',
                    'all_products' => '/collections/all',
                    'collections' => '/collections',
                    'collection' => isset($collections[$i['target_id'] ?? 0]) ? '/collections/'.$collections[$i['target_id']] : null,
                    'product' => isset($products[$i['target_id'] ?? 0]) ? '/products/'.$products[$i['target_id']] : null,
                    'page' => isset($pages[$i['target_id'] ?? 0]) ? '/pages/'.$pages[$i['target_id']] : null,
                    'search' => '/search',
                    'blog' => '/blog',
                    'url' => $i['url'] ?? null,
                    default => null,
                };
                if ($href === null) {
                    continue;
                }
                // Merchant's translation of this item, else built-in text for the default items ("Home"…)
                $title = $l10n->translating()
                    ? ($menu ? $l10n->pick('menu', $menu->id, 'item.'.$i['id'], __($i['title'])) : __($i['title']))
                    : $i['title'];
                $out[] = ['title' => $title, 'href' => $href, 'children' => $build($i['children'] ?? [])];
            }

            return $out;
        };

        return $build($items);
    }

    private function item(string $title, string $type): array
    {
        return ['id' => Str::lower(Str::random(8)), 'title' => $title, 'type' => $type, 'children' => []];
    }

    private function safeId(mixed $id): string
    {
        return is_string($id) && preg_match('/^[a-z0-9_-]{1,32}$/i', $id) ? $id : Str::lower(Str::random(8));
    }

    /** Lift grandchildren up one level (used when an item is nested too deep). */
    private function flatten(array $children): array
    {
        $out = [];
        foreach ($children as $c) {
            if (! is_array($c)) {
                continue;
            }
            $grand = $c['children'] ?? [];
            $c['children'] = [];
            $out[] = $c;
            foreach (is_array($grand) ? $this->flatten($grand) : [] as $g) {
                $out[] = $g;
            }
        }

        return $out;
    }
}
