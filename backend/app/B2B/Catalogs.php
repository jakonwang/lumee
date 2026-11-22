<?php

namespace App\B2B;

use App\Markets\MarketContext;
use App\Models\Market;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Store;
use App\Models\WholesaleCatalog;
use App\Pricing\PriceLists;
use App\Models\Order;
use App\Support\Money;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Wholesale catalogs (批发目录): a private link for a prospect or distributor with the products,
 * retail and wholesale prices (a price list) in a market's currency, minimum quantities and stock —
 * as a page, or as a JSON / CSV feed a distributor's system can import. With an order key, the
 * distributor's system can also place orders (priced by the catalog, waiting for the merchant to
 * confirm shipping and payment).
 */
class Catalogs
{
    public static function create(array $data): WholesaleCatalog
    {
        return WholesaleCatalog::create([...$data, 'token' => Str::random(40)]);
    }

    public static function url(Store $store, WholesaleCatalog $c, string $ext = ''): string
    {
        return $store->storefrontUrl().'/wholesale/catalog/'.$c->token.$ext;
    }

    /**
     * Products and prices as the catalog's market and price list see them.
     *
     * @return array{store: string, currency: string, market: ?string, price_list: ?string, generated_at: string, products: array}
     */
    public function build(Store $store, WholesaleCatalog $c): array
    {
        $market = $c->market_id ? Market::find($c->market_id) : null;
        $list = $c->price_list_id ? PriceList::find($c->price_list_id) : null;
        $markets = app(MarketContext::class);

        return $markets->within($market, function () use ($store, $c, $market, $list, $markets) {
            $prices = app(PriceLists::class);
            $prices->useTags($list && $list->enabled ? [$list->customer_tag] : null);
            try {
                $excluded = array_keys($markets->excluded());
                $products = Product::query()->where('status', 'active')
                    ->when($excluded, fn ($q) => $q->whereNotIn('id', $excluded))
                    ->when($c->collection_id, fn ($q) => $q->whereHas('collections', fn ($w) => $w->whereKey($c->collection_id)))
                    ->with(['variants', 'images'])->orderBy('title')->limit(2000)->get();
                $base = $store->storefrontUrl().($market ? $market->prefix() : '');

                return [
                    'store' => $store->name, 'currency' => $store->currency, 'market' => $market?->name, 'price_list' => $list?->name,
                    'generated_at' => now()->toIso8601String(),
                    'products' => $products->map(fn (Product $p) => [
                        'id' => $p->id, 'title' => $p->title, 'handle' => $p->handle, 'url' => $base.'/products/'.$p->handle,
                        'product_type' => $p->product_type, 'tags' => $p->tags ?? [],
                        'description' => Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags((string) $p->description))), 1000),
                        'images' => $p->images->map->url()->values()->all(),
                        'variants' => $p->variants->map(function ($v) use ($prices, $markets, $c) {
                            $retail = $markets->variantPrices($v->id, (int) $v->getRawOriginal('price'), null)[0];
                            $rules = $prices->rules($v->id);

                            return array_filter([
                                'id' => $v->id, 'sku' => $v->sku, 'title' => $v->title === 'Default' ? null : $v->title, 'options' => $v->options ?: null,
                                'retail_price' => $retail, 'price' => (int) $v->price,   // minor units, catalog currency
                                'min_qty' => $rules['min'], 'step' => $rules['step'],
                                'tiers' => $rules['tiers'] ?: null,
                                'stock' => $c->show_stock ? max(0, (int) $v->inventory_quantity) : null,
                            ], fn ($x) => $x !== null);
                        })->values()->all(),
                    ])->values()->all(),
                ];
            } finally {
                $prices->reset();
            }
        });
    }

    /**
     * A distributor's order through the API, priced exactly as the catalog shows it (market currency,
     * price list tiers, minimum quantities). The order waits for the merchant: shipping is added and
     * payment collected (manual / on account) before it ships. Sending the same PO number again
     * returns the first order instead of creating a duplicate.
     *
     * @param  array{email: string, po_number?: ?string, note?: ?string, shipping_address: array, items: array<int, array{variant_id?: int, sku?: string, quantity: int}>}  $data
     * @return array{0: Order, 1: bool}  the order, and whether it was created now
     */
    public function placeOrder(Store $store, WholesaleCatalog $c, array $data): array
    {
        if (! empty($data['po_number']) && ($existing = Order::where('wholesale_catalog_id', $c->id)->where('po_number', $data['po_number'])->first())) {
            return [$existing, false];
        }
        $market = $c->market_id ? Market::find($c->market_id) : null;
        $list = $c->price_list_id ? PriceList::find($c->price_list_id) : null;
        $markets = app(MarketContext::class);

        $order = $markets->within($market, function () use ($store, $c, $data, $list, $markets) {
            $prices = app(PriceLists::class);
            $prices->useTags($list && $list->enabled ? [$list->customer_tag] : null);
            try {
                $excluded = $markets->excluded();
                $lines = [];
                foreach ($data['items'] as $i => $item) {
                    $v = \App\Models\ProductVariant::with('product')
                        ->when(isset($item['variant_id']), fn ($q) => $q->whereKey($item['variant_id']), fn ($q) => $q->where('sku', (string) ($item['sku'] ?? '')))
                        ->first();
                    $inCatalog = $v && $v->product->status === 'active' && ! isset($excluded[$v->product_id])
                        && (! $c->collection_id || $v->product->collections()->whereKey($c->collection_id)->exists());
                    if (! $inCatalog) {
                        throw ValidationException::withMessages(["items.$i" => __('This item isn’t in the catalog.')]);
                    }
                    $qty = (int) $item['quantity'];
                    if ($error = $prices->quantityError($v->id, $qty)) {
                        throw ValidationException::withMessages(["items.$i.quantity" => $v->product->title.': '.$error]);
                    }
                    $lines[] = ['variant_id' => $v->id, 'quantity' => $qty, 'price' => Money::format($prices->unitPrice($v->id, $qty, (int) $v->price))];
                }
                $a = $data['shipping_address'];

                return app(\App\Http\Controllers\Admin\ManualOrderController::class)->create($store, [
                    'email' => $data['email'], 'first_name' => $a['first_name'] ?? null, 'last_name' => $a['last_name'] ?? null,
                    'phone' => $a['phone'] ?? null, 'address1' => $a['address1'], 'address2' => $a['address2'] ?? null,
                    'city' => $a['city'], 'province' => $a['province'] ?? null, 'zip' => $a['zip'] ?? null, 'country' => $a['country'],
                    'items' => $lines, 'shipping_price' => 0, 'charge_tax' => true, 'payment' => 'pending', 'notify' => true,
                    'gateway' => 'manual', 'source' => 'wholesale_api', 'catalog_name' => $c->name, 'wholesale_catalog_id' => $c->id,
                    'po_number' => $data['po_number'] ?? null, 'note' => $data['note'] ?? null,
                ], null);
            } finally {
                $prices->reset();
            }
        });

        return [$order, true];
    }

    /** What a distributor's system sees about one of its orders. */
    public static function orderPayload(Order $o): array
    {
        return [
            'number' => $o->number, 'name' => $o->name(), 'po_number' => $o->po_number, 'currency' => $o->currency,
            'subtotal' => $o->subtotal, 'shipping' => $o->shipping_total, 'tax' => $o->tax_total, 'total' => $o->total,   // minor units
            'financial_status' => $o->financial_status, 'fulfillment_status' => $o->fulfillment_status,
            'tracking_number' => $o->tracking_number, 'created_at' => $o->created_at?->toIso8601String(),
            'items' => $o->items->map(fn ($i) => ['variant_id' => $i->variant_id, 'sku' => $i->sku, 'title' => $i->title, 'variant' => $i->variant_title, 'quantity' => $i->quantity, 'price' => $i->price])->values()->all(),
        ];
    }
}
