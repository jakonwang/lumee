<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Notifications\OrderNotifier;
use App\Services\Inventory;
use App\Services\TaxService;
use App\Support\Money;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Orders created by the merchant (phone / WhatsApp / wholesale / replacement orders).
 * Stock is reserved like a normal order; payment is recorded as paid now or left pending
 * (bank transfer, cash on delivery). The buyer is emailed only if the merchant asks.
 */
class ManualOrderController extends Controller
{
    /** Variant search for the "add products" picker. */
    public function variants(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q'));
        $variants = ProductVariant::with('product:id,title,status,featured_image', 'image')
            ->whereHas('product', fn ($q) => $q->where('status', '!=', 'archived'))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('sku', $term)
                ->orWhereHas('product', fn ($p) => $p->where('title', 'like', '%'.addcslashes($term, '%_\\').'%'))))
            ->orderBy('product_id')->orderBy('position')->limit(40)->get();

        return response()->json(['data' => $variants->map(fn (ProductVariant $v) => [
            'id' => $v->id,
            'product_id' => $v->product_id,
            'title' => $v->product?->title,
            'variant_title' => $v->title === 'Default' ? null : $v->title,
            'sku' => $v->sku,
            'price' => Money::format($v->price),
            'inventory' => (int) $v->inventory_quantity,
            'image' => $v->image?->url() ?? $v->product?->featured_image,
            'status' => $v->product?->status,
        ]), 'countries' => $request->boolean('countries')
            ? collect(\App\Support\Countries::all())->map(fn ($name, $code) => ['code' => $code, 'name' => $name])->values()
            : null]);
    }

    public function store(Request $request, CurrentStore $current): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address1' => ['nullable', 'string', 'max:200'],
            'address2' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'zip' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'size:2'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.variant_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'items.*.price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],   // custom price (wholesale, replacement at 0)
            'shipping_name' => ['nullable', 'string', 'max:100'],
            'shipping_price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'discount_type' => ['nullable', Rule::in(['amount', 'percent'])],
            'discount_value' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'discount_reason' => ['nullable', 'string', 'max:100'],
            'charge_tax' => ['boolean'],
            'payment' => ['required', Rule::in(['paid', 'pending'])],
            'notify' => ['boolean'],
            'note' => ['nullable', 'string', 'max:2000'],
            'allow_oversell' => ['boolean'],
        ]);
        $store = $current->getOrFail();

        $make = fn () => $this->create($store, $data, $request->user()?->id);
        $order = ($data['notify'] ?? false) ? $make() : OrderNotifier::quietly($make);

        return response()->json(['data' => new OrderResource($order->load('items'))], 201);
    }

    /**
     * Also used for accepted quotes. Extra keys: gateway (manual|terms), source, company_id, po_number,
     * payment_due_days (terms), allow_oversell.
     */
    public function create(Store $store, array $data, ?int $userId): Order
    {
        $order = DB::transaction(function () use ($store, $data, $userId) {
            $variants = ProductVariant::with('product')->whereIn('id', array_column($data['items'], 'variant_id'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $lines = [];
            $subtotal = 0;
            foreach ($data['items'] as $i => $item) {
                $v = $variants->get($item['variant_id']);
                if (! $v) {
                    throw ValidationException::withMessages(["items.$i.variant_id" => __('商品不存在')]);
                }
                $needed = collect($data['items'])->where('variant_id', $v->id)->sum('quantity');
                if (! ($data['allow_oversell'] ?? false) && $v->inventory_quantity < $needed) {
                    throw ValidationException::withMessages(["items.$i.quantity" => __('「:p0」库存只剩 :p1 件', ['p0' => $v->product->title, 'p1' => $v->inventory_quantity])]);
                }
                $price = isset($item['price']) && $item['price'] !== '' ? Money::toMinor($item['price']) : $v->price;
                $lineTotal = $price * $item['quantity'];
                $subtotal += $lineTotal;
                $lines[] = [$v, (int) $item['quantity'], $price, $lineTotal];
            }

            $discount = 0;
            if (($data['discount_value'] ?? 0) > 0) {
                $discount = ($data['discount_type'] ?? 'amount') === 'percent'
                    ? (int) round($subtotal * min(100, (float) $data['discount_value']) / 100)
                    : min($subtotal, Money::toMinor($data['discount_value']));
            }
            $shipping = isset($data['shipping_price']) ? Money::toMinor($data['shipping_price']) : 0;
            $total = $subtotal - $discount + $shipping;

            $tax = ['amount' => 0, 'included' => false, 'lines' => []];
            if ($data['charge_tax'] ?? false) {
                $taxable = array_sum(array_map(fn ($l) => $l[0]->product->taxable ? $l[3] : 0, $lines));
                $tax = app(TaxService::class)->calculate($store, TaxService::taxableAfterDiscount($subtotal, $taxable, $discount),
                    $shipping, $data['country'] ?? null, $data['province'] ?? null, $data);
                if (! $tax['included']) {
                    $total += $tax['amount'];
                }
            }

            $customer = Customer::firstOrNew(['email' => Str::lower($data['email'])]);
            $customer->fill(array_filter([
                'first_name' => $data['first_name'] ?? null, 'last_name' => $data['last_name'] ?? null, 'phone' => $data['phone'] ?? null,
            ], fn ($x) => $x !== null && $x !== '') + ['first_name' => $customer->first_name]);
            $customer->orders_count = ($customer->orders_count ?? 0) + 1;
            $customer->save();

            $lockedStore = Store::whereKey($store->id)->lockForUpdate()->first();
            $number = max(1000, (int) Order::where('store_id', $lockedStore->id)->max('number')) + 1;

            $order = Order::create([
                'number' => $number,
                'token' => (string) Str::uuid(),
                'customer_id' => $customer->id,
                'email' => $customer->email,
                'currency' => $store->currency,
                'locale' => $customer->locale ?? $store->locale ?? 'en',
                'subtotal' => $subtotal,
                'shipping_total' => $shipping,
                'discount_total' => $discount,
                'discount_lines' => $discount ? [['type' => 'manual', 'id' => null, 'title' => $data['discount_reason'] ?? __('手动折扣'), 'amount' => $discount]] : null,
                'tax_total' => $tax['amount'],
                'taxes_included' => $tax['included'],
                'tax_lines' => $tax['lines'] ?: null,
                'total' => max(0, $total),
                'shipping_address' => collect($data)->only('first_name', 'last_name', 'address1', 'address2', 'city', 'province', 'zip', 'country', 'phone')
                    ->filter(fn ($x) => $x !== null && $x !== '')->all(),
                'shipping_method' => ($data['shipping_name'] ?? null) || $shipping ? ['id' => null, 'name' => $data['shipping_name'] ?? __('运费'), 'description' => null, 'price' => $shipping] : null,
                'payment_gateway' => $data['gateway'] ?? 'manual',
                'financial_status' => 'pending',
                'fulfillment_status' => 'unfulfilled',
                'note' => $data['note'] ?? null,
            ]);
            $order->forceFill([
                'source' => $data['source'] ?? 'admin', 'created_by' => $userId, 'company_id' => $data['company_id'] ?? null,
                'po_number' => $data['po_number'] ?? null, 'wholesale_catalog_id' => $data['wholesale_catalog_id'] ?? null,
                // Created inside a market (distributor orders through a market catalog): its currency and rate
                'market_id' => app(\App\Markets\MarketContext::class)->market()?->id, 'exchange_rate' => app(\App\Markets\MarketContext::class)->rate(),
                'payment_due_at' => ($data['gateway'] ?? null) === 'terms' ? now()->addDays(max(1, (int) ($data['payment_due_days'] ?? 30))) : null,
            ])->save();

            foreach ($lines as [$v, $qty, $price, $lineTotal]) {
                $order->items()->create([
                    'product_id' => $v->product_id,
                    'variant_id' => $v->id,
                    'title' => $v->product->title,
                    'variant_title' => $v->title === 'Default' ? null : $v->title,
                    'sku' => $v->sku,
                    'image' => $v->image?->url() ?? $v->product->featured_image,
                    'price' => $price,
                    'quantity' => $qty,
                    'total' => $lineTotal,
                ]);
                Inventory::change($v->id, -$qty, 'order', $order->id);
            }
            $order->log('placed', match ($data['source'] ?? 'admin') {
                'quote' => __('买家接受了报价 :p0', ['p0' => $data['quote_name'] ?? '']),
                'wholesale_api' => __('分销商通过批发目录「:p0」的接口下单，确认运费和付款方式后再发货', ['p0' => $data['catalog_name'] ?? '']),
                default => __('商家在后台创建了订单'),
            });

            return $order;
        });

        app(OrderNotifier::class)->orderPlaced($order);
        if ($data['payment'] === 'paid') {
            $order->markPaid();
        }
        app(\App\Webhooks\Webhooks::class)->orderEvent($order->fresh(), 'orders/create');

        return $order->fresh();
    }
}
