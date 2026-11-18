<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\CustomerSubscription;
use App\Subscriptions\Subscriptions;
use App\Tenancy\CurrentStore;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Buyer's subscription page (the unguessable token in the link is the proof): skip, pause, resume, cancel. */
class SubscriptionController extends Controller
{
    public function show(string $token, CurrentStore $current): View
    {
        $sub = CustomerSubscription::where('token', $token)->firstOrFail();
        $orders = $sub->orders()->latest('id')->limit(10)->get(['id', 'number', 'token', 'total', 'currency', 'financial_status', 'created_at']);
        $origin = \App\Models\Order::find($sub->origin_order_id, ['id', 'number', 'token', 'total', 'currency', 'financial_status', 'created_at']);

        return view($current->themeView('subscription'), ['sub' => $sub, 'orders' => $origin ? $orders->push($origin) : $orders]);
    }

    public function update(Request $request, string $token, Subscriptions $subscriptions): RedirectResponse
    {
        $sub = CustomerSubscription::where('token', $token)->firstOrFail();
        $action = $request->validate(['action' => ['required', Rule::in(['skip', 'pause', 'resume', 'cancel'])]])['action'];
        abort_if($sub->status === 'cancelled', 422);
        match ($action) {
            'skip' => $sub->status === 'active' ? $subscriptions->skip($sub) : null,
            'pause' => $subscriptions->pause($sub),
            'resume' => $subscriptions->resume($sub),
            'cancel' => $subscriptions->cancel($sub),
        };

        return back()->with('status', [
            'skip' => __('Your next delivery has been skipped.'), 'pause' => __('Your subscription is paused.'),
            'resume' => __('Your subscription is active again.'), 'cancel' => __('Your subscription has been cancelled.'),
        ][$action]);
    }
}
