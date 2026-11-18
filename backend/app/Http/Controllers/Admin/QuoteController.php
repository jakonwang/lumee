<?php

namespace App\Http\Controllers\Admin;

use App\B2B\Quotes;
use App\Http\Controllers\Controller;
use App\Models\Quote;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Customers › Quotes: answer quote requests with prices, shipping and an expiry. */
class QuoteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $page = Quote::with('company:id,name')->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))->latest('id')->paginate(20);
        $page->getCollection()->transform(fn (Quote $q) => $this->row($q));

        return response()->json([...$page->toArray(), 'counts' => Quote::selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status')]);
    }

    public function show(int $id): JsonResponse
    {
        $q = Quote::with('company:id,name')->findOrFail($id);

        return response()->json(['data' => [...$this->row($q), 'buyer_note' => $q->buyer_note, 'merchant_note' => $q->merchant_note,
            'shipping_address' => $q->shipping_address, 'shipping_price' => $q->shipping_price !== null ? Money::format($q->shipping_price) : null,
            'expires_on' => $q->expires_on?->toDateString(), 'order_id' => $q->order_id,
            'items' => collect($q->items)->map(fn ($i) => [...$i, 'list_price' => Money::format((int) $i['list_price']), 'price' => $i['price'] !== null ? Money::format((int) $i['price']) : null]),
            'url' => \App\Models\Store::find($q->store_id)->storefrontUrl()."/quotes/{$q->token}"]]);
    }

    public function send(Request $request, int $id, Quotes $quotes): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'max:200'],
            'items.*.variant_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'items.*.price' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'shipping_price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:today'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $quotes->send(Quote::findOrFail($id), $data['items'], isset($data['shipping_price']) ? Money::toMinor($data['shipping_price']) : null,
            $data['expires_on'] ?? null, $data['note'] ?? null);

        return $this->show($id);
    }

    public function decline(int $id, Quotes $quotes): JsonResponse
    {
        $quotes->decline(Quote::findOrFail($id));

        return $this->show($id);
    }

    private function row(Quote $q): array
    {
        return ['id' => $q->id, 'name' => $q->name(), 'status' => $q->status === 'sent' && $q->isExpired() ? 'expired' : $q->status,
            'email' => $q->email, 'company' => $q->company?->name, 'items_count' => count($q->items),
            'subtotal' => Money::format($q->subtotal()), 'created_at' => $q->created_at, 'sent_at' => $q->sent_at];
    }
}
