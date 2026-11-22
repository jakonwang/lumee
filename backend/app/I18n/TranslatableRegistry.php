<?php

namespace App\I18n;

use App\Models\Collection;
use App\Models\Menu;
use App\Models\Page;
use App\Models\Product;
use App\Models\ShippingRate;
use App\Models\Store;
use App\Payments\PaymentSettings;
use App\Services\MenuService;
use App\Themes\ThemeService;
use Illuminate\Support\Collection as Coll;

/**
 * What merchants can translate, per resource type: each resource lists its fields with the
 * original (default-language) text. Field keys match what the storefront looks up
 * (see HasTranslations, ThemeContext, MenuService, PaymentSettings).
 */
class TranslatableRegistry
{
    public const TYPES = [
        'product' => '商品',
        'collection' => '商品分类',
        'page' => '页面',
        'post' => '博客文章',
        'menu' => '导航菜单',
        'theme' => '店铺装修文字',
        'shipping' => '运费名称',
        'store' => '收款说明',
    ];

    /**
     * @return array{items: Coll, total: int}
     *   items: [{id, label, fields: [{key, label, source, multiline}]}]
     */
    public function list(Store $store, string $type, ?string $q, int $page, int $per = 20): array
    {
        $like = $q ? '%'.addcslashes($q, '%_\\').'%' : null;
        $paged = fn ($query) => [(clone $query)->count(), $query->forPage($page, $per)->get()];

        switch ($type) {
            case 'product':
                [$total, $rows] = $paged(Product::with('options')->when($like, fn ($w) => $w->where('title', 'like', $like))->orderBy('title'));
                $items = $rows->map(fn (Product $p) => $this->product($p));
                break;
            case 'collection':
                [$total, $rows] = $paged(Collection::when($like, fn ($w) => $w->where('title', 'like', $like))->orderBy('title'));
                $items = $rows->map(fn (Collection $c) => ['id' => $c->id, 'label' => $c->getRawOriginal('title'), 'fields' => $this->seoFields($c, [
                    ['key' => 'title', 'label' => __('名称'), 'source' => $c->getRawOriginal('title')],
                    ['key' => 'description', 'label' => __('描述'), 'source' => (string) $c->getRawOriginal('description'), 'multiline' => true],
                ])]);
                break;
            case 'page':
                [$total, $rows] = $paged(Page::when($like, fn ($w) => $w->where('title', 'like', $like))->orderBy('title'));
                $items = $rows->map(fn (Page $p) => ['id' => $p->id, 'label' => $p->getRawOriginal('title'), 'fields' => $this->seoFields($p, [
                    ['key' => 'title', 'label' => __('标题'), 'source' => $p->getRawOriginal('title')],
                    ['key' => 'body', 'label' => __('内容（Markdown）'), 'source' => (string) $p->getRawOriginal('body'), 'multiline' => true],
                ])]);
                break;
            case 'post':
                [$total, $rows] = $paged(\App\Models\BlogPost::when($like, fn ($w) => $w->where('title', 'like', $like))->orderByDesc('published_at'));
                $items = $rows->map(fn (\App\Models\BlogPost $p) => ['id' => $p->id, 'label' => $p->getRawOriginal('title'), 'fields' => $this->seoFields($p, [
                    ['key' => 'title', 'label' => __('标题'), 'source' => $p->getRawOriginal('title')],
                    ['key' => 'excerpt', 'label' => __('摘要'), 'source' => (string) $p->getRawOriginal('excerpt'), 'multiline' => true],
                    ['key' => 'body', 'label' => __('内容（Markdown）'), 'source' => (string) $p->getRawOriginal('body'), 'multiline' => true],
                ])]);
                break;
            case 'menu':
                $menus = collect(Menu::HANDLES)->keys()->map(fn ($h) => app(MenuService::class)->get($h));
                $items = $menus->map(fn (Menu $m) => ['id' => $m->id, 'label' => __($m->title), 'fields' => $this->menuFields($m->items ?? [])]);
                $total = $items->count();
                break;
            case 'theme':
                $items = collect([['id' => $store->id, 'label' => __('当前主题的文字'), 'fields' => $this->themeFields($store)]]);
                $total = 1;
                break;
            case 'shipping':
                [$total, $rows] = $paged(ShippingRate::with('zone')->orderBy('zone_id')->orderBy('position'));
                $items = $rows->map(fn (ShippingRate $r) => ['id' => $r->id, 'label' => $r->getRawOriginal('name').($r->zone ? ' · '.$r->zone->name : ''), 'fields' => array_values(array_filter([
                    ['key' => 'name', 'label' => __('名称'), 'source' => $r->getRawOriginal('name')],
                    $r->getRawOriginal('description') ? ['key' => 'description', 'label' => __('送达时间说明'), 'source' => $r->getRawOriginal('description')] : null,
                ]))]);
                break;
            case 'store':
                $p = PaymentSettings::for($store)->all()['manual'];
                $items = collect([['id' => $store->id, 'label' => __('线下付款'), 'fields' => [
                    ['key' => 'payment_label', 'label' => __('付款方式名称'), 'source' => $p['label']],
                    ['key' => 'payment_instructions', 'label' => __('付款说明'), 'source' => (string) $p['instructions'], 'multiline' => true],
                ]]]);
                $total = 1;
                break;
            default:
                abort(404);
        }

        return ['items' => $items->map(fn ($i) => [...$i, 'fields' => array_values(array_filter($i['fields'], fn ($f) => trim((string) $f['source']) !== ''))])->values(), 'total' => $total];
    }

    /** Field keys a resource accepts (anything else is ignored when saving). */
    public function allowedKeys(Store $store, string $type, int $id): array
    {
        $item = match ($type) {
            'product' => ($p = Product::with('options')->find($id)) ? $this->product($p) : null,
            'menu', 'theme', 'store' => $this->list($store, $type, null, 1, 1000)['items']->firstWhere('id', $id),
            default => null,
        };
        if (! $item) {
            $model = match ($type) { 'collection' => Collection::class, 'page' => Page::class, 'post' => \App\Models\BlogPost::class, 'shipping' => ShippingRate::class, default => null };
            abort_unless($model && $model::whereKey($id)->exists(), 404);   // store-scoped

            return match ($type) {
                'shipping' => ['name', 'description'],
                'page' => ['title', 'body', 'seo_title', 'seo_description'],
                'post' => ['title', 'excerpt', 'body', 'seo_title', 'seo_description'],
                default => ['title', 'description', 'seo_title', 'seo_description'],
            };
        }

        return array_column($item['fields'], 'key');
    }

    private function product(Product $p): array
    {
        $fields = [
            ['key' => 'title', 'label' => __('商品名称'), 'source' => $p->getRawOriginal('title')],
            ['key' => 'description', 'label' => __('商品描述'), 'source' => (string) $p->getRawOriginal('description'), 'multiline' => true],
            ['key' => 'product_type', 'label' => __('商品类型'), 'source' => (string) $p->getRawOriginal('product_type')],
        ];
        foreach ($p->options as $o) {
            $name = $o->getRawOriginal('name');
            $fields[] = ['key' => "option.{$name}", 'label' => __('规格名：:p0', ['p0' => $name]), 'source' => $name];
            foreach ((array) json_decode((string) $o->getRawOriginal('values'), true) as $v) {
                $fields[] = ['key' => "option.{$name}.{$v}", 'label' => __(':p0：:p1', ['p0' => $name, 'p1' => $v]), 'source' => $v];
            }
        }

        return ['id' => $p->id, 'label' => $p->getRawOriginal('title'), 'fields' => $this->seoFields($p, $fields)];
    }

    private function seoFields($model, array $fields): array
    {
        $seo = json_decode((string) $model->getRawOriginal('seo'), true) ?: [];
        $fields[] = ['key' => 'seo_title', 'label' => __('SEO 标题'), 'source' => (string) ($seo['title'] ?? '')];
        $fields[] = ['key' => 'seo_description', 'label' => __('SEO 描述'), 'source' => (string) ($seo['description'] ?? ''), 'multiline' => true];

        return $fields;
    }

    private function menuFields(array $items, string $prefix = ''): array
    {
        $out = [];
        foreach ($items as $i) {
            $out[] = ['key' => 'item.'.$i['id'], 'label' => $prefix.$i['title'], 'source' => $i['title']];
            $out = [...$out, ...$this->menuFields($i['children'] ?? [], $i['title'].' › ')];
        }

        return $out;
    }

    /** Text / textarea settings and section fields of the store's live theme. */
    private function themeFields(Store $store): array
    {
        $ctx = app(ThemeService::class)->resolve($store);
        $schema = config("themes.{$ctx->theme}");
        $fields = [];
        foreach ($schema['settings'] as $group => $g) {
            foreach ($g['fields'] as $key => $f) {
                if (in_array($f['type'], ['text', 'textarea'], true)) {
                    $fields[] = ['key' => "settings.{$group}.{$key}", 'label' => "{$g['label']} · {$f['label']}",
                        'source' => (string) ($ctx->config['settings'][$group][$key] ?? ''), 'multiline' => $f['type'] === 'textarea'];
                }
            }
        }
        // Blocks of the home page, the product / collection / cart templates and campaign pages (ids are unique)
        $lists = [__('首页') => $ctx->config['sections']];
        foreach ((array) ($ctx->config['templates'] ?? []) as $tpl => $list) {
            $lists[__($schema['templates'][$tpl]['label'] ?? $tpl)] = (array) $list;
        }
        $titles = \App\Models\Page::where('layout', 'blocks')->pluck('title', 'id');
        foreach ((array) ($ctx->config['pages'] ?? []) as $pageId => $list) {
            if (isset($titles[$pageId])) {
                $lists[__('活动页 · :title', ['title' => $titles[$pageId]])] = (array) $list;
            }
        }
        foreach ($lists as $where => $list) {
            foreach (array_values($list) as $n => $s) {
                $def = $schema['sections'][$s['type']] ?? null;
                foreach ($def['fields'] ?? [] as $key => $f) {
                    if (in_array($f['type'], ['text', 'textarea'], true)) {
                        $fields[] = ['key' => "sections.{$s['id']}.{$key}", 'label' => $where.' · '.($n + 1).'. '.$def['label'].' · '.$f['label'],
                            'source' => (string) ($s['settings'][$key] ?? ''), 'multiline' => $f['type'] === 'textarea'];
                    }
                }
            }
        }

        return $fields;
    }
}
