<?php

namespace App\Http\Controllers\Storefront;

use App\Collect\Item;
use App\Collect\Pipeline;
use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Supply\Purchasing;
use App\Tenancy\CurrentStore;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Supplier portal (供应商端): a private link per supplier — no account needed. They see the purchase
 * orders sent to them, confirm them with a ship date, mark them shipped, and offer new products
 * (which land in the store's collection pool for review).
 */
class SupplierPortalController extends Controller
{
    public function show(string $token, CurrentStore $current): View
    {
        $supplier = $this->supplier($token);

        return view('storefront.supplier-portal', [
            'store' => $current->getOrFail(), 'supplier' => $supplier,
            'orders' => PurchaseOrder::where('supplier_id', $supplier->id)->whereNotIn('status', ['draft'])->latest('id')->limit(50)->get(),
        ]);
    }

    public function update(Request $request, string $token, int $order, Purchasing $purchasing): RedirectResponse
    {
        $supplier = $this->supplier($token);
        $po = PurchaseOrder::where('supplier_id', $supplier->id)->findOrFail($order);
        $data = $request->validate([
            'action' => ['required', Rule::in(['confirm', 'ship'])],
            'expected_at' => ['nullable', 'date', 'after_or_equal:today'],
            'tracking' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $purchasing->supplierUpdate($po, $data['action'], $data);

        return back()->with('status', $data['action'] === 'confirm' ? __('Order confirmed. Thank you!') : __('Marked as shipped. Thank you!'));
    }

    /** A new product offer → the store's collection pool (Products › Collect), marked as from this supplier. */
    public function offer(Request $request, string $token, CurrentStore $current, Pipeline $pipeline): RedirectResponse
    {
        $supplier = $this->supplier($token);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'price' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'currency' => ['required', Rule::in(['CNY', 'USD', 'EUR', 'HKD'])],
            'moq' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'url' => ['nullable', 'url', 'max:1000'],
            'images' => ['nullable', 'string', 'max:4000'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);
        $images = array_values(array_filter(preg_split('/\s+/', (string) ($data['images'] ?? '')), fn ($u) => Item::url($u) !== null));
        // Without a product page, the offer gets its own address so offering it again updates it instead of duplicating
        $url = $data['url'] ?? Purchasing::portalUrl($current->getOrFail(), $supplier).'?offer='.Str::slug(Str::limit($data['title'], 60, ''));
        $description = trim(($data['description'] ?? '').(! empty($data['moq']) ? "\n\nMOQ: ".$data['moq'] : ''));
        $item = $pipeline->receive($current->getOrFail(), $url, 'supplier', Item::normalize([
            'title' => $data['title'], 'description' => nl2br(e($description)), 'images' => $images, 'currency' => $data['currency'],
            'variants' => [['options' => [], 'cost' => (int) round($data['price'] * 100)]],
        ]));
        $item->forceFill(['checks' => [...($item->checks ?? []), 'supplier' => ['id' => $supplier->id, 'name' => $supplier->name, 'moq' => $data['moq'] ?? null]]])->save();

        return back()->with('status', __('Thanks — the store will review your product.'));
    }

    private function supplier(string $token): Supplier
    {
        return Supplier::where('token', $token)->firstOrFail();   // this store's only
    }
}
