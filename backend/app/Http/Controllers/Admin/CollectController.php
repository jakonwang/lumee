<?php

namespace App\Http\Controllers\Admin;

use App\Collect\CollectAi;
use App\Collect\Item;
use App\Collect\Parsers;
use App\Collect\Pipeline;
use App\Collect\PublishCollected;
use App\Collect\RunCollection;
use App\Http\Controllers\Controller;
use App\Models\CollectedItem;
use App\Models\Collection;
use App\Models\Market;
use App\Models\PricingRule;
use App\Models\Store;
use App\Support\Money;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Products › Collect (采集): collect from URLs / a whole Shopify store / the browser extension,
 * review and edit in the pool, AI rewrite, reprice, and publish in bulk.
 */
class CollectController extends Controller
{
    public const MAX_URLS = 50;

    public function index(Request $request, CurrentStore $current): JsonResponse
    {
        $status = $request->query('status', 'ready');
        $q = trim((string) $request->query('q'));
        $items = CollectedItem::query()
            ->when(in_array($status, CollectedItem::STATUSES, true), fn ($w) => $w->where('status', $status))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('url', 'like', '%'.addcslashes($q, '%_\\').'%')->orWhere('data', 'like', '%'.addcslashes($q, '%_\\').'%')))
            ->latest('id')->paginate(30);
        $store = $current->getOrFail();

        return response()->json([
            'data' => collect($items->items())->map(fn ($i) => $this->row($i)),
            'meta' => ['total' => $items->total(), 'page' => $items->currentPage(), 'last_page' => $items->lastPage()],
            'counts' => CollectedItem::selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status'),
            'store_currency' => $store->baseCurrency(),
            'ai_ready' => CollectAi::ready($store),
            'extension_connected' => ! empty($store->settings['collector_key_hash']),
            'markets' => Market::orderBy('position')->get(['id', 'name', 'currency']),
            'collections' => Collection::orderBy('title')->get(['id', 'title']),
            'rules' => PricingRule::orderBy('position')->orderBy('id')->get(['id', 'name']),
            'currencies' => array_values(array_unique([...config('lumee.currencies'), 'CNY', 'USD'])),
        ]);
    }

    /** Collect product URLs (one per line), or every product of a Shopify store. */
    public function store(Request $request, CurrentStore $current): JsonResponse
    {
        $data = $request->validate([
            'urls' => ['required', 'string', 'max:20000'],
            'mode' => ['required', Rule::in(['products', 'store'])],
            'currency' => ['required', 'string', 'size:3'],
        ]);
        $urls = collect(preg_split('/[\s,]+/', $data['urls']))->map(fn ($u) => trim($u))->filter(fn ($u) => Item::url($u) !== null)->unique()->values();
        if ($urls->isEmpty()) {
            throw ValidationException::withMessages(['urls' => __('没有有效的网址（需要以 http:// 或 https:// 开头）')]);
        }
        if ($urls->count() > self::MAX_URLS) {
            throw ValidationException::withMessages(['urls' => __('一次最多 :max 个网址', ['max' => self::MAX_URLS])]);
        }
        $store = $current->getOrFail();
        $currency = strtoupper($data['currency']);

        if ($data['mode'] === 'store') {
            $marker = CollectedItem::updateOrCreate(['url_hash' => sha1('store:'.$urls[0])], ['url' => $urls[0], 'platform' => 'shopify', 'status' => 'pending', 'error' => null]);
            RunCollection::dispatch($store->id, [$marker->id], $currency, $urls[0]);

            return response()->json(['queued' => 1, 'skipped' => 0], 202);
        }

        $ids = [];
        $skipped = 0;
        foreach ($urls as $url) {
            $item = CollectedItem::firstOrNew(['url_hash' => Item::hash($url)]);
            if ($item->exists && $item->status === 'published') {
                $skipped++;   // already in the catalog

                continue;
            }
            $item->fill(['url' => $url, 'status' => 'pending', 'error' => null])->save();
            $ids[] = $item->id;
        }
        if ($ids) {
            RunCollection::dispatch($store->id, $ids, $currency);
        }

        return response()->json(['queued' => count($ids), 'skipped' => $skipped], 202);
    }

    public function show(CollectedItem $item): JsonResponse
    {
        return response()->json(['data' => $this->row($item, true)]);
    }

    /** Save edits from the review drawer. Prices are decimal amounts in the store currency, costs in the source currency. */
    public function update(Request $request, CollectedItem $item): JsonResponse
    {
        abort_if($item->status === 'published', 422, __('已上架的商品请在商品页修改'));
        $v = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:60000'],
            'product_type' => ['nullable', 'string', 'max:120'],
            'tags' => ['array', 'max:30'],
            'tags.*' => ['string', 'max:60'],
            'images' => ['array', 'max:'.Item::MAX_IMAGES],
            'images.*' => ['string', 'max:2000'],
            'variants' => ['required', 'array'],
            'variants.*.price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'variants.*.compare_at_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'variants.*.cost' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'variants.*.sku' => ['nullable', 'string', 'max:100'],
            'variants.*.inventory' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);
        $d = $item->data ?? [];
        $d['title'] = $v['title'];
        $d['description'] = (string) ($v['description'] ?? '');
        $d['product_type'] = $v['product_type'] ?? null;
        $d['tags'] = $v['tags'] ?? [];
        $d['images'] = array_values(array_filter(array_map(fn ($u) => Item::url($u), $v['images'] ?? [])));
        foreach ($d['variants'] as $i => $old) {
            $new = $v['variants'][$i] ?? null;
            if (! $new) {
                continue;
            }
            $money = fn ($x) => $x === null || $x === '' ? null : Money::toMinor($x);
            $d['variants'][$i] = [...$old,
                'price' => $money($new['price'] ?? null), 'compare_at_price' => $money($new['compare_at_price'] ?? null),
                'cost' => $money($new['cost'] ?? null) ?? $old['cost'], 'sku' => $new['sku'] ?? $old['sku'],
                'inventory' => $new['inventory'] ?? $old['inventory']];
        }
        $item->forceFill(['data' => Item::normalize($d), 'error' => null])->save();   // an old publish error no longer applies

        return response()->json(['data' => $this->row($item, true)]);
    }

    /**
     * Bulk actions on selected items: ai | reprice | reject | restore | delete | publish.
     * AI runs here (≤ 10 at a time, it's slow); publishing goes to the queue (image downloads).
     */
    public function bulk(Request $request, CurrentStore $current, Pipeline $pipeline): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['ai', 'reprice', 'reject', 'restore', 'delete', 'publish'])],
            'rule_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['draft', 'active'])],
            'collection_ids' => ['array'],
            'collection_ids.*' => ['integer'],
            'market_ids' => ['nullable', 'array'],
            'market_ids.*' => ['integer'],
            'inventory' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);
        $store = $current->getOrFail();
        $items = CollectedItem::whereIn('id', $data['ids'])->get();   // this store's only
        $done = 0;
        $errors = [];

        switch ($data['action']) {
            case 'ai':
                if (! CollectAi::ready($store)) {
                    throw ValidationException::withMessages(['action' => __('还没有配置 Claude API Key（在「获客雷达 › 设置」里填写）')]);
                }
                if ($items->count() > 10) {
                    throw ValidationException::withMessages(['ids' => __('AI 优化一次最多 10 个商品')]);
                }
                foreach ($items->where('status', 'ready') as $item) {
                    try {
                        $pipeline->rewrite($store, $item);
                        $done++;
                    } catch (Throwable $e) {
                        $errors[] = ['id' => $item->id, 'message' => Str::limit($e->getMessage(), 200)];
                    }
                }
                break;
            case 'reprice':
                $rule = isset($data['rule_id']) ? PricingRule::findOrFail($data['rule_id']) : null;
                foreach ($items->where('status', 'ready') as $item) {
                    try {
                        $pipeline->reprice($store, $item, $rule);
                        $done++;
                    } catch (Throwable $e) {
                        $errors[] = ['id' => $item->id, 'message' => $e->getMessage()];
                    }
                }
                break;
            case 'reject':
                $done = CollectedItem::whereIn('id', $items->whereIn('status', ['ready', 'failed', 'pending'])->pluck('id'))->update(['status' => 'rejected']);
                break;
            case 'restore':
                $done = CollectedItem::whereIn('id', $items->where('status', 'rejected')->pluck('id'))->update(['status' => 'ready']);
                break;
            case 'delete':
                $done = CollectedItem::whereIn('id', $items->where('status', '!=', 'published')->pluck('id'))->delete();
                break;
            case 'publish':
                $ready = $items->where('status', 'ready');
                if ($ids = array_values(array_filter((array) ($data['collection_ids'] ?? [])))) {
                    if (Collection::whereIn('id', $ids)->count() !== count($ids)) {
                        throw ValidationException::withMessages(['collection_ids' => __('包含不存在的分类')]);
                    }
                }
                if ($ready->isNotEmpty()) {
                    PublishCollected::dispatch($store->id, $ready->pluck('id')->all(), [
                        'status' => $data['status'] ?? 'draft',
                        'collection_ids' => $ids,
                        'market_ids' => isset($data['market_ids']) ? Market::whereIn('id', $data['market_ids'])->pluck('id')->all() : null,
                        'inventory' => $data['inventory'] ?? 0,
                    ]);
                }
                $done = $ready->count();
                break;
        }

        return response()->json(['done' => $done, 'errors' => $errors]);
    }

    public function rules(): JsonResponse
    {
        return response()->json(['data' => PricingRule::orderBy('position')->orderBy('id')->get()->map(fn ($r) => $this->rule($r))]);
    }

    /** Replace the whole ordered list of pricing rules. */
    public function saveRules(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rules' => ['present', 'array', 'max:50'],
            'rules.*.name' => ['required', 'string', 'max:80'],
            'rules.*.scope' => ['required', Rule::in(PricingRule::SCOPES)],
            'rules.*.scope_value' => ['nullable', 'required_unless:rules.*.scope,all', 'string', 'max:120'],
            'rules.*.multiplier' => ['required', 'numeric', 'min:0.1', 'max:100'],
            'rules.*.add_amount' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'rules.*.compare_multiplier' => ['nullable', 'numeric', 'min:1', 'max:10'],
            'rules.*.rounding' => ['required', Rule::in(Market::ROUNDING)],
        ]);
        \Illuminate\Support\Facades\DB::transaction(function () use ($data) {
            PricingRule::query()->delete();
            foreach ($data['rules'] as $i => $r) {
                PricingRule::create([...$r, 'scope_value' => $r['scope'] === 'all' ? null : $r['scope_value'],
                    'add_amount' => Money::toMinor($r['add_amount'] ?? 0), 'position' => $i]);
            }
        });

        return $this->rules();
    }

    /** A new key for the browser extension (the old one stops working). Shown once. */
    public function extensionKey(CurrentStore $current): JsonResponse
    {
        $store = $current->getOrFail();
        $key = 'lmc_'.$store->id.'_'.Str::random(40);
        $settings = $store->settings ?? [];
        $settings['collector_key_hash'] = hash('sha256', $key);
        $store->forceFill(['settings' => $settings])->save();

        return response()->json(['key' => $key, 'api' => rtrim(config('app.url'), '/')]);
    }

    /**
     * The browser extension sends the page the merchant has open (it may be behind a login or bot
     * check that the server can't pass). Authenticated by the store's collector key only.
     */
    public function fromExtension(Request $request, CurrentStore $current, Pipeline $pipeline): JsonResponse
    {
        $key = (string) $request->header('X-Collector-Key');
        $store = preg_match('/^lmc_(\d+)_[A-Za-z0-9]{40}$/', $key, $m) ? Store::find((int) $m[1]) : null;
        if (! $store || empty($store->settings['collector_key_hash']) || ! hash_equals($store->settings['collector_key_hash'], hash('sha256', $key))) {
            return response()->json(['message' => __('采集密钥无效，请在后台「商品 › 采集」重新生成')], 401);
        }
        $data = $request->validate([
            'url' => ['required', 'string', 'max:2000'],
            'html' => ['nullable', 'string', 'max:1600000'],
            'extracted' => ['nullable', 'array'],
            'extracted.title' => ['nullable', 'string', 'max:500'],
            'extracted.description' => ['nullable', 'string', 'max:20000'],
            'extracted.images' => ['nullable', 'array', 'max:40'],
            'extracted.images.*' => ['string', 'max:2000'],
            'extracted.price' => ['nullable', 'string', 'max:50'],
            'extracted.currency' => ['nullable', 'string', 'size:3'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);
        $url = Item::url($data['url']) ?? abort(422, __('网址无效'));
        $current->set($store);
        $currency = strtoupper($data['currency'] ?? $data['extracted']['currency'] ?? 'USD');

        $parsed = ($data['html'] ?? '') !== '' ? Parsers::page($data['html'], $url, $currency) : null;
        $parsed ??= Parsers::extracted($data['extracted'] ?? [], $url, $currency);
        if (! $parsed) {
            return response()->json(['message' => __('这个页面上没有找到商品信息')], 422);
        }
        $item = $pipeline->receive($store, $url, 'extension', $parsed);

        return response()->json(['id' => $item->id, 'title' => $item->data['title'] ?? '', 'status' => $item->status], 201);
    }

    private function row(CollectedItem $i, bool $full = false): array
    {
        $d = $i->data ?? [];
        $prices = collect($d['variants'] ?? [])->pluck('price')->filter();
        $costs = collect($d['variants'] ?? [])->pluck('cost')->filter();

        return [
            'id' => $i->id, 'url' => $i->url, 'platform' => $i->platform, 'status' => $i->status, 'error' => $i->error,
            'title' => $d['title'] ?? null, 'image' => $d['images'][0] ?? null, 'currency' => $d['currency'] ?? null,
            'variants_count' => count($d['variants'] ?? []),
            'price_min' => $prices->isEmpty() ? null : Money::format($prices->min()), 'price_max' => $prices->isEmpty() ? null : Money::format($prices->max()),
            'cost_min' => $costs->isEmpty() ? null : Money::format($costs->min()),
            'rule' => $d['pricing']['rule'] ?? null,
            'notes' => $i->checks['notes'] ?? [], 'duplicate' => $i->checks['duplicate'] ?? null, 'ai' => $i->checks['ai'] ?? null,
            'product_id' => $i->product_id, 'created_at' => $i->created_at,
        ] + ($full ? ['data' => [...$d, 'variants' => array_map(fn ($v) => [...$v,
            'price' => $v['price'] === null ? null : Money::format($v['price']),
            'compare_at_price' => $v['compare_at_price'] === null ? null : Money::format($v['compare_at_price']),
            'cost' => Money::format((int) $v['cost'])], $d['variants'] ?? [])], 'original' => $i->original] : []);
    }

    private function rule(PricingRule $r): array
    {
        return [...$r->only('id', 'name', 'scope', 'scope_value', 'multiplier', 'compare_multiplier', 'rounding', 'position'), 'add_amount' => Money::format($r->add_amount)];
    }
}
