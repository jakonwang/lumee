<?php

namespace App\Http\Controllers\Admin;

use App\B2B\Catalogs;
use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Lead;
use App\Models\Market;
use App\Models\PriceList;
use App\Models\WholesaleCatalog;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Wholesale catalogs (批发目录): shareable links + JSON / CSV feeds for prospects and distributors. */
class WholesaleCatalogController extends Controller
{
    public function index(CurrentStore $current): JsonResponse
    {
        $store = $current->getOrFail();

        return response()->json([
            'data' => WholesaleCatalog::latest('id')->get()->map(fn ($c) => $this->row($store, $c)),
            'markets' => Market::orderBy('position')->get(['id', 'name', 'currency']),
            'price_lists' => PriceList::orderBy('name')->get(['id', 'name', 'market_id', 'enabled']),
            'collections' => Collection::orderBy('title')->get(['id', 'title']),
        ]);
    }

    public function store(Request $request, CurrentStore $current): JsonResponse
    {
        $c = Catalogs::create($this->validated($request));

        return response()->json(['data' => $this->row($current->getOrFail(), $c)], 201);
    }

    public function update(Request $request, WholesaleCatalog $catalog, CurrentStore $current): JsonResponse
    {
        $catalog->update($this->validated($request));

        return response()->json(['data' => $this->row($current->getOrFail(), $catalog)]);
    }

    public function destroy(WholesaleCatalog $catalog): JsonResponse
    {
        $catalog->delete();

        return response()->json(['ok' => true]);
    }

    /** Turn the order API on (new key — the old one stops working), or off. */
    public function orderKey(Request $request, WholesaleCatalog $catalog, CurrentStore $current): JsonResponse
    {
        $on = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        $catalog->forceFill(['order_key' => $on ? 'ok_'.\Illuminate\Support\Str::random(45) : null])->save();

        return response()->json(['data' => $this->row($current->getOrFail(), $catalog)]);
    }

    /**
     * A catalog for a lead from the lead finder: in the market that sells to the lead's country (if any),
     * with the first enabled price list for that market (or a general one). Reused if the lead has one.
     */
    public function forLead(Lead $lead, CurrentStore $current): JsonResponse
    {
        $store = $current->getOrFail();
        $existing = WholesaleCatalog::where('lead_id', $lead->id)->latest('id')->first();
        if ($existing && $existing->isLive()) {
            return response()->json(['data' => $this->row($store, $existing)]);
        }
        $country = strtoupper((string) $lead->country);
        $market = Market::where('enabled', true)->get()->first(fn ($m) => in_array($country, (array) $m->countries, true));
        $list = PriceList::where('enabled', true)->where('market_id', $market?->id)->orderBy('id')->first()
            ?? PriceList::where('enabled', true)->whereNull('market_id')->orderBy('id')->first();
        $c = Catalogs::create(['name' => mb_substr($lead->name, 0, 120), 'market_id' => $market?->id, 'price_list_id' => $list?->id,
            'lead_id' => $lead->id, 'show_stock' => true, 'expires_at' => now()->addDays(90)]);

        return response()->json(['data' => $this->row($store, $c)], 201);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'market_id' => ['nullable', 'integer'],
            'price_list_id' => ['nullable', 'integer'],
            'collection_id' => ['nullable', 'integer'],
            'show_stock' => ['boolean'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);
        foreach (['market_id' => Market::class, 'price_list_id' => PriceList::class, 'collection_id' => Collection::class] as $key => $model) {
            if (! empty($data[$key]) && ! $model::whereKey($data[$key])->exists()) {   // this store's only
                throw ValidationException::withMessages([$key => __('不存在')]);
            }
            $data[$key] = ($data[$key] ?? null) ?: null;
        }

        return $data;
    }

    private function row($store, WholesaleCatalog $c): array
    {
        return [
            ...$c->only('id', 'name', 'market_id', 'price_list_id', 'collection_id', 'lead_id', 'show_stock', 'expires_at', 'views', 'last_viewed_at', 'created_at'),
            'live' => $c->isLive(),
            'url' => Catalogs::url($store, $c), 'json' => Catalogs::url($store, $c, '.json'), 'csv' => Catalogs::url($store, $c, '.csv'),
            // Distributor order API: the key is shown to the merchant only (it's hidden from the model's JSON)
            'order_key' => $c->order_key, 'orders_url' => Catalogs::url($store, $c, '/orders'),
            'orders_count' => \App\Models\Order::where('wholesale_catalog_id', $c->id)->count(),
        ];
    }
}
