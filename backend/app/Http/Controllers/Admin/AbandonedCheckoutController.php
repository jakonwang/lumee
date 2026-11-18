<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Marketing\CheckoutRecovery;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Discount;
use App\Support\Money;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Abandoned checkouts (carts that reached checkout with an email) + the recovery email settings. */
class AbandonedCheckoutController extends Controller
{
    public function index(Request $request, CurrentStore $current, CheckoutRecovery $recovery): JsonResponse
    {
        $store = $current->getOrFail();
        $page = Cart::with('items.variant.product')
            ->whereNotNull('checkout_started_at')->whereNotNull('email')
            ->where('checkout_started_at', '>=', now()->subDays(30))
            ->whereHas('items')
            ->latest('updated_at')
            ->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (Cart $c) => [
                'id' => $c->id,
                'email' => $c->email,
                'items' => $c->items->filter(fn (CartItem $i) => $i->variant?->product)->map(fn (CartItem $i) => [
                    'title' => $i->variant->product->title,
                    'variant_title' => $i->variant->title === 'Default' ? null : $i->variant->title,
                    'quantity' => $i->quantity,
                ])->values(),
                'item_count' => $c->itemCount(),
                'subtotal' => Money::format($c->subtotal()),
                'started_at' => $c->checkout_started_at,
                'last_activity_at' => $c->updated_at,
                'recovery_sent_at' => $c->recovery_sent_at,
                'locale' => $c->locale,
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'currency' => $store->currency,
            'stats' => $recovery->stats($store),
            'settings' => CheckoutRecovery::settings($store),
            'delays' => CheckoutRecovery::DELAYS,
        ]);
    }

    public function updateSettings(Request $request, CurrentStore $current): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'delay_hours' => ['required', 'integer', Rule::in(CheckoutRecovery::DELAYS)],
            'discount_code' => ['nullable', 'string', 'max:50'],
        ]);
        if (filled($data['discount_code'] ?? null) && ! Discount::where('code', Discount::normalizeCode($data['discount_code']))->exists()) {
            throw ValidationException::withMessages(['discount_code' => __('找不到这个折扣码，请先在「折扣」里创建')]);
        }

        $store = $current->getOrFail();
        CheckoutRecovery::save($store, $data);

        return response()->json(['settings' => CheckoutRecovery::settings($store->fresh())]);
    }
}
