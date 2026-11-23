<?php

namespace App\Http\Controllers\Admin;

use App\Assistant\ShoppingAssistant;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Online store › AI 导购: switch the shopping assistant on, its greeting and monthly allowance, what shoppers asked. */
class AssistantController extends Controller
{
    public function show(CurrentStore $current): JsonResponse
    {
        $store = $current->getOrFail();
        $rows = DB::table('assistant_questions')->where('store_id', $store->id)->orderByDesc('id')->limit(50)->get();
        $ids = $rows->flatMap(fn ($r) => (array) json_decode((string) $r->product_ids, true))->unique()->values();
        $titles = Product::whereIn('id', $ids)->pluck('title', 'id');

        return response()->json([
            'status' => ShoppingAssistant::status($store),
            'questions' => $rows->map(fn ($r) => [
                'id' => $r->id, 'question' => $r->question, 'reply' => $r->reply, 'locale' => $r->locale, 'created_at' => $r->created_at,
                'products' => collect((array) json_decode((string) $r->product_ids, true))->map(fn ($id) => $titles[$id] ?? null)->filter()->values(),
            ]),
            'week' => DB::table('assistant_questions')->where('store_id', $store->id)->where('created_at', '>=', now()->subDays(7))->count(),
        ]);
    }

    public function update(Request $request, CurrentStore $current): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'greeting' => ['nullable', 'string', 'max:300'],
            'quota' => ['required', 'integer', 'min:0', 'max:20000'],
        ]);
        $store = $current->getOrFail();
        $settings = $store->settings ?? [];
        $settings['assistant'] = [...(array) ($settings['assistant'] ?? []), 'enabled' => $data['enabled'], 'greeting' => trim((string) ($data['greeting'] ?? '')), 'quota' => $data['quota']];
        $store->forceFill(['settings' => $settings])->save();

        return response()->json(['status' => ShoppingAssistant::status($store->fresh())]);
    }
}
