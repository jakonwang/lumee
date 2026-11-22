<?php

namespace App\Http\Controllers\Storefront;

use App\B2B\Catalogs;
use App\Http\Controllers\Controller;
use App\Models\WholesaleCatalog;
use App\Support\Money;
use App\Tenancy\CurrentStore;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A wholesale catalog link: the page, a JSON / CSV feed for distributors' systems, and — when the
 * merchant turned it on — an order API authenticated with the catalog's order key
 * (Authorization: Bearer <order key>).
 */
class WholesaleCatalogController extends Controller
{
    public function show(string $token, CurrentStore $current, Catalogs $catalogs): View
    {
        $c = $this->find($token, true);
        $store = $current->getOrFail();

        return view('storefront.wholesale-catalog', [
            'store' => $store, 'catalog' => $c, 'data' => $catalogs->build($store, $c),
            'json' => Catalogs::url($store, $c, '.json'), 'csv' => Catalogs::url($store, $c, '.csv'),
        ]);
    }

    public function json(string $token, CurrentStore $current, Catalogs $catalogs): JsonResponse
    {
        $c = $this->find($token, true);

        return response()->json($catalogs->build($current->getOrFail(), $c), 200, ['X-Robots-Tag' => 'noindex']);
    }

    public function csv(string $token, CurrentStore $current, Catalogs $catalogs): StreamedResponse
    {
        $c = $this->find($token, true);
        $d = $catalogs->build($current->getOrFail(), $c);

        return response()->streamDownload(function () use ($d) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // Excel reads UTF-8
            fputcsv($out, ['product_id', 'variant_id', 'sku', 'title', 'variant', 'product_type', 'currency', 'retail_price', 'wholesale_price', 'min_qty', 'step', 'stock', 'url', 'image']);
            foreach ($d['products'] as $p) {
                foreach ($p['variants'] as $v) {
                    fputcsv($out, [$p['id'], $v['id'], $v['sku'] ?? '', $p['title'], $v['title'] ?? '', $p['product_type'] ?? '', $d['currency'],
                        Money::format($v['retail_price']), Money::format($v['price']), $v['min_qty'], $v['step'], $v['stock'] ?? '', $p['url'], $p['images'][0] ?? '']);
                }
            }
            fclose($out);
        }, 'wholesale-catalog.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Robots-Tag' => 'noindex']);
    }

    /** POST …/wholesale/catalog/{token}/orders — place an order (201), or the earlier one for a repeated PO number (200). */
    public function placeOrder(Request $request, string $token, CurrentStore $current, Catalogs $catalogs): JsonResponse
    {
        $c = $this->authorized($request, $token);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'po_number' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:1000'],
            'shipping_address' => ['required', 'array'],
            'shipping_address.first_name' => ['nullable', 'string', 'max:100'],
            'shipping_address.last_name' => ['nullable', 'string', 'max:100'],
            'shipping_address.company' => ['nullable', 'string', 'max:150'],
            'shipping_address.address1' => ['required', 'string', 'max:255'],
            'shipping_address.address2' => ['nullable', 'string', 'max:255'],
            'shipping_address.city' => ['required', 'string', 'max:100'],
            'shipping_address.province' => ['nullable', 'string', 'max:100'],
            'shipping_address.zip' => ['nullable', 'string', 'max:20'],
            'shipping_address.country' => ['required', Rule::in(array_keys(\App\Support\Countries::all()))],
            'shipping_address.phone' => ['nullable', 'string', 'max:40'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.variant_id' => ['required_without:items.*.sku', 'integer'],
            'items.*.sku' => ['required_without:items.*.variant_id', 'string', 'max:100'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);
        [$order, $created] = $catalogs->placeOrder($current->getOrFail(), $c, $data);

        return response()->json(['order' => Catalogs::orderPayload($order->load('items'))], $created ? 201 : 200);
    }

    /** GET …/wholesale/catalog/{token}/orders/{number} — status and tracking of an order placed through this catalog. */
    public function order(Request $request, string $token, int $number): JsonResponse
    {
        $c = $this->authorized($request, $token);
        $order = \App\Models\Order::where('wholesale_catalog_id', $c->id)->where('number', $number)->with('items')->first();
        abort_unless($order, 404);

        return response()->json(['order' => Catalogs::orderPayload($order)]);
    }

    /** The catalog, when it takes API orders and the request carries its order key. */
    private function authorized(Request $request, string $token): WholesaleCatalog
    {
        $c = $this->find($token, false);
        $key = (string) $request->bearerToken();
        abort_unless($c->order_key && $key !== '' && hash_equals($c->order_key, $key), 401, 'Invalid order key.');

        return $c;
    }

    private function find(string $token, bool $count): WholesaleCatalog
    {
        $c = WholesaleCatalog::where('token', $token)->first();   // this store's only
        abort_unless($c && $c->isLive(), 404);
        if ($count) {
            DB::table('wholesale_catalogs')->where('id', $c->id)->update(['views' => DB::raw('views + 1'), 'last_viewed_at' => now()]);
        }

        return $c;
    }
}
