<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ReturnRequest;
use App\Returns\Returns;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Orders › Returns: review buyers' return requests, and the store's return policy settings. */
class ReturnController extends Controller
{

    public function index(Request $request, CurrentStore $current): JsonResponse
    {
        $page = ReturnRequest::with('order.items')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('order_id'), fn ($q, $id) => $q->where('order_id', (int) $id))
            ->latest('id')->paginate(20);
        $page->getCollection()->transform(fn (ReturnRequest $r) => $this->row($r));

        return response()->json([
            ...$page->toArray(),
            'counts' => ReturnRequest::selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status'),
            'settings' => Returns::settings($current->getOrFail()),
            'reasons' => collect(ReturnRequest::ADMIN_REASONS)->map(fn ($l) => __($l)),
        ]);
    }

    public function update(Request $request, int $id, Returns $returns): JsonResponse
    {
        $r = ReturnRequest::with('order.items', 'order.store')->findOrFail($id);
        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'declined', 'completed'])],
            'note' => ['nullable', 'string', 'max:1000'],
            'instructions' => ['nullable', 'string', 'max:1000'],
        ]);
        $returns->decide($r, $data['status'], $data['note'] ?? null, $data['instructions'] ?? null);

        return response()->json(['data' => $this->row($r->fresh('order.items'))]);
    }

    public function settings(Request $request, CurrentStore $current): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'window_days' => ['required', 'integer', 'min:1', 'max:365'],
            'instructions' => ['nullable', 'string', 'max:1000'],
        ]);
        $store = $current->getOrFail();
        $store->settings = [...($store->settings ?? []), 'returns' => [...$data, 'instructions' => $data['instructions'] ?? '']];
        $store->save();

        return response()->json(['settings' => Returns::settings($store)]);
    }

    private function row(ReturnRequest $r): array
    {
        $o = $r->order;

        return [
            'id' => $r->id,
            'status' => $r->status,
            'reason' => $r->reason,
            'reason_label' => __(ReturnRequest::ADMIN_REASONS[$r->reason] ?? $r->reason),
            'customer_note' => $r->customer_note,
            'merchant_note' => $r->merchant_note,
            'return_instructions' => $r->return_instructions,
            'order' => ['id' => $o->id, 'name' => $o->name(), 'email' => $o->email, 'currency' => $o->currency],
            'items' => collect($r->items)->map(function ($i) use ($o) {
                $item = $o->items->firstWhere('id', $i['order_item_id']);

                return ['order_item_id' => $i['order_item_id'], 'title' => $item?->title, 'variant_title' => $item?->variant_title,
                    'image' => $item?->image, 'quantity' => $i['quantity'], 'price' => $item ? \App\Support\Money::format($item->price) : null];
            })->values(),
            'decided_at' => $r->decided_at,
            'created_at' => $r->created_at,
        ];
    }
}
