<?php

namespace App\Http\Controllers\Storefront;

use App\B2B\Applications;
use App\B2B\B2B;
use App\B2B\Quotes;
use App\Customers\CustomerSession;
use App\Http\Controllers\Controller;
use App\Models\Quote;
use App\Services\CartService;
use App\Support\Countries;
use App\Tenancy\CurrentStore;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Storefront B2B: wholesale application form, request a quote from the bag, view / accept a quote. */
class B2BController extends Controller
{
    public function applyForm(CurrentStore $current): View
    {
        abort_unless(B2B::settings($current->getOrFail())['applications'], 404);

        return view($current->themeView('wholesale'), ['countries' => Countries::all()]);
    }

    public function apply(Request $request, CurrentStore $current, Applications $applications): RedirectResponse
    {
        $store = $current->getOrFail();
        abort_unless(B2B::settings($store)['applications'], 404);
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:150'],
            'tax_id' => ['nullable', 'string', 'max:60'],
            'contact_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'website' => ['nullable', 'string', 'max:200'],
            'country' => ['nullable', Rule::in(array_keys(Countries::all()))],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);
        $applications->apply($store, $data);

        return back()->with('status', __('Thanks! We’ve received your application and will email you once it’s reviewed.'));
    }

    /** Bag → quote request (signed-in buyers). */
    public function requestQuote(Request $request, CurrentStore $current, CartService $carts, Quotes $quotes): RedirectResponse
    {
        $customer = CustomerSession::user();
        if (! $customer) {
            return redirect('/account/login?return=/cart');
        }
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $cart = $carts->current();
        if (! $cart || $cart->items->isEmpty()) {
            return redirect('/cart');
        }
        $quote = $quotes->request($current->getOrFail(), $cart, $customer, $data['note'] ?? null);
        $carts->clear();

        return redirect("/quotes/{$quote->token}")->with('status', __('Quote requested. We’ll email you when it’s ready.'));
    }

    public function show(string $token, CurrentStore $current): View
    {
        $quote = Quote::where('token', $token)->firstOrFail();
        $order = $quote->order_id ? \App\Models\Order::find($quote->order_id, ['id', 'token', 'number']) : null;

        return view($current->themeView('quote'), ['quote' => $quote, 'order' => $order]);
    }

    public function accept(Request $request, string $token, Quotes $quotes): RedirectResponse
    {
        $quote = Quote::where('token', $token)->firstOrFail();
        $data = $request->validate(['po_number' => ['nullable', 'string', 'max:60']]);
        $order = $quotes->accept($quote, $data['po_number'] ?? null);

        return redirect("/orders/{$order->token}")->with('status', __('Quote accepted — your order has been placed.'));
    }

    public function decline(string $token, Quotes $quotes): RedirectResponse
    {
        $quotes->decline(Quote::where('token', $token)->firstOrFail());

        return back()->with('status', __('Quote declined.'));
    }
}
