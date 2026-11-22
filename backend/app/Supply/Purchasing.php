<?php

namespace App\Supply;

use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Store;
use App\Models\Supplier;
use App\Services\Inventory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Restocking (采购): what to reorder (sales speed vs stock and what's already on its way), purchase
 * orders to suppliers, the supplier's confirmation / shipment through their portal link, and
 * receiving the goods into stock.
 */
class Purchasing
{
    public const COVER_DAYS = 30;      // order enough for this many days of sales

    public const SALES_DAYS = 14;      // sales speed measured over this many days

    /**
     * Variants that will run out: sold in the last 14 days, projected over 30 days, minus stock and
     * what's already ordered. Hot test-and-learn products with little stock are included too.
     *
     * @return Collection<int, array{variant_id: int, product_id: int, title: string, variant_title: ?string, sku: ?string, supplier_id: ?int, stock: int, inbound: int, sold: int, suggested: int, cost: ?int, hot: bool}>
     */
    public function suggestions(Store $store): Collection
    {
        $sold = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.store_id', $store->id)->whereIn('orders.financial_status', Order::PAID_STATUSES)
            ->where('orders.created_at', '>=', now()->subDays(self::SALES_DAYS))->whereNotNull('order_items.variant_id')
            ->groupBy('order_items.variant_id')->selectRaw('order_items.variant_id, SUM(order_items.quantity - order_items.refunded_quantity) as units')
            ->pluck('units', 'variant_id');
        $inbound = $this->inbound();
        $variants = ProductVariant::with('product:id,title,status,supplier_id,test_status')
            ->whereHas('product', fn ($q) => $q->where('status', 'active'))
            ->where(fn ($q) => $q->whereIn('id', $sold->keys())->orWhereHas('product', fn ($p) => $p->where('test_status', 'hot')))
            ->get();

        return $variants->map(function (ProductVariant $v) use ($sold, $inbound) {
            $units = (int) ($sold[$v->id] ?? 0);
            $stock = max(0, (int) $v->inventory_quantity);
            $coming = (int) ($inbound[$v->id] ?? 0);
            $hot = $v->product->test_status === 'hot';
            $need = (int) ceil($units / self::SALES_DAYS * self::COVER_DAYS) - $stock - $coming;
            if ($hot && $stock + $coming < 10) {
                $need = max($need, 10 - $stock - $coming);   // a potential hit shouldn't run dry while it's tested
            }

            return ['variant_id' => $v->id, 'product_id' => $v->product_id, 'title' => $v->product->title,
                'variant_title' => $v->title === 'Default' ? null : $v->title, 'sku' => $v->sku, 'supplier_id' => $v->product->supplier_id,
                'stock' => $stock, 'inbound' => $coming, 'sold' => $units, 'suggested' => max(0, $need), 'cost' => $v->cost, 'hot' => $hot];
        })->filter(fn ($r) => $r['suggested'] > 0)->sortByDesc('suggested')->values();
    }

    /** Units on open purchase orders (sent, confirmed or shipped), per variant. */
    public function inbound(): array
    {
        $out = [];
        foreach (PurchaseOrder::whereIn('status', ['sent', 'confirmed', 'shipped'])->get(['items']) as $po) {
            foreach ($po->items as $i) {
                $out[$i['variant_id']] = ($out[$i['variant_id']] ?? 0) + (int) $i['quantity'];
            }
        }

        return $out;
    }

    /** @param  array<int, array{variant_id: int, quantity: int, cost?: ?int}>  $lines  cost in minor units (store currency) */
    public function create(Supplier $supplier, array $lines, ?string $note = null): PurchaseOrder
    {
        $variants = ProductVariant::with('product:id,title')->whereIn('id', array_column($lines, 'variant_id'))->get()->keyBy('id');   // this store's only
        $items = [];
        foreach ($lines as $l) {
            $v = $variants->get($l['variant_id']);
            if (! $v || (int) $l['quantity'] <= 0) {
                continue;
            }
            $items[] = ['variant_id' => $v->id, 'product_id' => $v->product_id, 'title' => $v->product->title,
                'variant_title' => $v->title === 'Default' ? null : $v->title, 'sku' => $v->sku,
                'quantity' => (int) $l['quantity'], 'cost' => (int) ($l['cost'] ?? $v->cost ?? 0)];
        }
        if (! $items) {
            throw ValidationException::withMessages(['items' => __('至少要有一个商品')]);
        }

        return DB::transaction(function () use ($supplier, $items, $note) {
            $store = Store::whereKey($supplier->store_id)->lockForUpdate()->first();
            $number = max(1000, (int) PurchaseOrder::where('store_id', $store->id)->max('number')) + 1;

            return PurchaseOrder::create([
                'supplier_id' => $supplier->id, 'number' => $number, 'status' => 'draft', 'items' => $items, 'note' => $note,
                'total' => array_sum(array_map(fn ($i) => $i['quantity'] * $i['cost'], $items)),
            ]);
        });
    }

    /** Send to the supplier: the order becomes visible on their portal (and is emailed when we have an address). */
    public function send(Store $store, PurchaseOrder $po): void
    {
        $this->expect($po, ['draft']);
        $po->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
        $supplier = $po->supplier;
        if ($supplier->email) {
            $link = self::portalUrl($store, $supplier);
            rescue(fn () => Mail::raw(
                "{$store->name} — {$po->name()}\n\n".collect($po->items)->map(fn ($i) => "- {$i['title']}".($i['variant_title'] ? " ({$i['variant_title']})" : '')."  SKU {$i['sku']}  × {$i['quantity']}")->implode("\n")
                ."\n\n".($po->note ? $po->note."\n\n" : '').__('Please confirm the order and the ship date here: :link', ['link' => $link]),
                fn ($m) => $m->to($supplier->email)->subject("{$store->name} · {$po->name()}"),
            ));
        }
    }

    /** Supplier portal: confirm (with a ship date) or mark shipped (with tracking). */
    public function supplierUpdate(PurchaseOrder $po, string $action, array $data): void
    {
        if ($action === 'confirm') {
            $this->expect($po, ['sent', 'confirmed']);
            $po->forceFill(['status' => 'confirmed', 'confirmed_at' => $po->confirmed_at ?? now(), 'expected_at' => $data['expected_at'] ?? $po->expected_at,
                'supplier_note' => $data['note'] ?? $po->supplier_note])->save();
        } else {
            $this->expect($po, ['sent', 'confirmed']);
            $po->forceFill(['status' => 'shipped', 'shipped_at' => now(), 'tracking' => $data['tracking'] ?? null,
                'supplier_note' => $data['note'] ?? $po->supplier_note])->save();
        }
    }

    /** Goods arrived: add them to stock (logged as "purchase") and keep the latest cost on each variant. */
    public function receive(PurchaseOrder $po): void
    {
        $this->expect($po, ['sent', 'confirmed', 'shipped']);
        DB::transaction(function () use ($po) {
            foreach ($po->items as $i) {
                Inventory::change((int) $i['variant_id'], (int) $i['quantity'], 'purchase', null, $po->name());
                if (! empty($i['cost'])) {
                    ProductVariant::whereKey($i['variant_id'])->update(['cost' => (int) $i['cost']]);
                }
            }
            $po->forceFill(['status' => 'received', 'received_at' => now()])->save();
        });
    }

    public function cancel(PurchaseOrder $po): void
    {
        $this->expect($po, ['draft', 'sent', 'confirmed']);
        $po->forceFill(['status' => 'cancelled'])->save();
    }

    public static function newToken(): string
    {
        return Str::random(40);
    }

    public static function portalUrl(Store $store, Supplier $s): string
    {
        return $store->storefrontUrl().'/supplier/'.$s->token;
    }

    private function expect(PurchaseOrder $po, array $statuses): void
    {
        if (! in_array($po->status, $statuses, true)) {
            throw ValidationException::withMessages(['status' => __('这张采购单现在不能这样操作')]);
        }
    }
}
