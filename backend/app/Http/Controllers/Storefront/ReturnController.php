<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Returns\Returns;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Order status page › "Request a return". The order token in the URL is the buyer's proof. */
class ReturnController extends Controller
{
    public function store(Request $request, string $token, Returns $returns): RedirectResponse
    {
        $order = Order::with('items', 'store')->where('token', $token)->firstOrFail();
        $data = $request->validate([
            'items' => ['required', 'array', 'max:100'],
            'items.*' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'reason' => ['required', Rule::in(array_keys(ReturnRequest::REASONS))],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $returns->request($order, $data['items'], $data['reason'], $data['note'] ?? null);

        return back()->with('status', __('Return requested. We will email you once it is reviewed.'));
    }
}
