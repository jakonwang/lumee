<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ProductVariant;
use App\Support\Money;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;

/**
 * Home page: totals, a to-do list and the latest orders. Every member can open it, but only sees
 * figures for areas they can access. Period analytics (funnel, sources…) come from /analytics.
 */
class DashboardController extends Controller
{
    public const LOW_STOCK = 5;

    private const OPEN_DISPUTES = ['needs_response', 'warning_needs_response', 'under_review', 'warning_under_review'];

    public function __invoke(CurrentStore $current): JsonResponse
    {
        $data = ['currency' => $current->getOrFail()->currency, 'todos' => []];

        if ($current->can('analytics')) {
            // Net sales (after refunds), in the store currency (market orders converted at their order's rate)
            $data['revenue'] = Money::format((int) round((float) Order::whereIn('financial_status', Order::PAID_STATUSES)->sum(\DB::raw('(total - refunded_total) / exchange_rate'))));
        }
        if ($current->can('orders')) {
            $data['orders'] = Order::count();
            $data['to_fulfill'] = Order::whereIn('financial_status', ['paid', 'partially_refunded'])
                ->where('fulfillment_status', 'unfulfilled')->whereNull('cancelled_at')->count();
            $data['awaiting_payment'] = Order::where('financial_status', 'pending')->whereNull('cancelled_at')->count();
            $disputes = Order::whereNotNull('dispute')->get(['id', 'dispute'])
                ->filter(fn ($o) => in_array($o->dispute['status'] ?? null, self::OPEN_DISPUTES, true))->count();
            $abandoned = Cart::whereNotNull('checkout_started_at')->whereNotNull('email')
                ->where('checkout_started_at', '>=', now()->subDays(7))->whereHas('items')->count();
            $data['todos'] = [
                ['key' => 'to_fulfill', 'count' => $data['to_fulfill'], 'route' => 'orders'],
                ['key' => 'awaiting_payment', 'count' => $data['awaiting_payment'], 'route' => 'orders'],
                ['key' => 'disputes', 'count' => $disputes, 'route' => 'orders'],
                ['key' => 'abandoned', 'count' => $abandoned, 'route' => 'abandoned-checkouts'],
                ['key' => 'overdue_invoices', 'count' => Order::where('payment_gateway', 'terms')->where('financial_status', 'pending')
                    ->whereNull('cancelled_at')->where('payment_due_at', '<', now())->count(), 'route' => 'companies'],
            ];
            $data['recent_orders'] = Order::latest('id')->limit(6)
                ->get(['id', 'number', 'email', 'shipping_address', 'total', 'currency', 'financial_status', 'fulfillment_status', 'cancelled_at', 'created_at'])
                ->map(fn (Order $o) => [
                    'id' => $o->id,
                    'number' => $o->number,
                    'customer' => $o->shipping_address['name'] ?? $o->email,
                    'total' => Money::format($o->total),
                    'currency' => $o->currency,
                    'financial_status' => $o->financial_status,
                    'fulfillment_status' => $o->fulfillment_status,
                    'cancelled' => (bool) $o->cancelled_at,
                    'created_at' => $o->created_at,
                ]);
        }
        if ($current->can('products')) {
            $data['products'] = Product::count();
            $data['todos'][] = ['key' => 'low_stock', 'count' => ProductVariant::where('inventory_quantity', '<=', self::LOW_STOCK)
                ->whereHas('product', fn ($q) => $q->where('status', 'active'))->distinct()->count('product_id'), 'route' => 'products'];
            $data['todos'][] = ['key' => 'pending_reviews', 'count' => ProductReview::where('status', 'pending')->count(), 'route' => 'reviews'];
        }
        if ($current->can('online_store')) {
            // Campaign designs prepared from the shopping calendar, waiting for the merchant's OK
            $data['todos'][] = ['key' => 'campaign_designs', 'count' => \App\Models\ThemeSchedule::where('status', 'review')->count(), 'route' => 'online-store'];
        }
        if ($current->can('customers')) {
            $data['customers'] = Customer::count();
            $data['todos'][] = ['key' => 'quote_requests', 'count' => \App\Models\Quote::where('status', 'requested')->count(), 'route' => 'quotes'];
            $data['todos'][] = ['key' => 'b2b_applications', 'count' => \App\Models\B2bApplication::where('status', 'pending')->count(), 'route' => 'companies'];
        }

        return response()->json($data);
    }
}
