<?php

namespace App\Http\Controllers\Storefront;

use App\Analytics\Visits;
use App\Assistant\ShoppingAssistant;
use App\Http\Controllers\Controller;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /assistant — one question to the AI shopping assistant (history is kept by the browser). */
class AssistantController extends Controller
{
    public function ask(Request $request, CurrentStore $current, ShoppingAssistant $assistant): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:500'],
            'history' => ['nullable', 'array', 'max:12'],
            'history.*.role' => ['required', 'in:shopper,assistant'],
            'history.*.text' => ['required', 'string', 'max:2000'],
            'viewing' => ['nullable', 'string', 'max:255'],
        ]);
        $store = $current->getOrFail();
        if (! ShoppingAssistant::available($store)) {
            return response()->json(['message' => __('The shopping assistant is not available right now.')], 503);
        }

        try {
            $answer = $assistant->answer($store, trim($data['message']), $data['history'] ?? [], $data['viewing'] ?? null, app(Visits::class)->visitorId());
        } catch (\RuntimeException $e) {
            report($e);

            return response()->json(['message' => __('Sorry, I can’t answer right now. Please try again in a moment.')], 503);
        }

        return response()->json($answer);
    }
}
