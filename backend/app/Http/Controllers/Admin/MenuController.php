<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Menu;
use App\Models\Product;
use App\Services\MenuService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    public function __construct(private MenuService $menus) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => collect(Menu::HANDLES)->keys()->map(fn ($h) => $this->present($this->menus->get($h)))->values(),
            'types' => \App\Support\Tr::map(MenuService::TYPES),
            'pages' => \App\Models\Page::orderBy('title')->get(['id', 'title', 'published'])->map(fn ($p) => [
                'id' => $p->id, 'title' => $p->title.($p->published ? '' : __('（未发布）')),
            ]),
        ]);
    }

    public function update(Request $request, string $handle): JsonResponse
    {
        abort_unless(array_key_exists($handle, Menu::HANDLES), 404);
        $request->validate(['items' => ['present', 'array', 'max:'.MenuService::MAX_ITEMS]]);

        $menu = $this->menus->get($handle);
        $menu->items = $this->menus->sanitize($request->input('items'));
        $menu->save();

        return response()->json(['data' => $this->present($menu)]);
    }

    /** Menu with target labels, so the editor can show "分类：Necklaces" without extra requests. */
    private function present(Menu $menu): array
    {
        $collections = Collection::pluck('title', 'id');
        $products = Product::pluck('title', 'id');
        $pages = \App\Models\Page::pluck('title', 'id');

        $label = function (array $items) use (&$label, $collections, $products, $pages) {
            return array_map(function ($i) use (&$label, $collections, $products, $pages) {
                $i['target_label'] = match ($i['type']) {
                    'collection' => $collections[$i['target_id']] ?? null,
                    'product' => $products[$i['target_id']] ?? null,
                    'page' => $pages[$i['target_id']] ?? null,
                    default => null,
                };
                // Target deleted since the menu was saved — the storefront hides it; the editor flags it
                $i['broken'] = in_array($i['type'], ['collection', 'product', 'page'], true) && $i['target_label'] === null;
                $i['children'] = $label($i['children'] ?? []);

                return $i;
            }, $items);
        };

        return ['handle' => $menu->handle, 'title' => __($menu->title), 'items' => $label($menu->items ?? [])];
    }
}
