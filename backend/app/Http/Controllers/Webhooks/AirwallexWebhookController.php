<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Payments\AirwallexGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Airwallex webhooks — subscribe to payment_intent.succeeded. */
class AirwallexWebhookController extends Controller
{
    public function __invoke(Request $request, AirwallexGateway $airwallex): JsonResponse
    {
        $body = $request->getContent();
        if (! AirwallexGateway::validSignature($body, $request->header('x-timestamp'), $request->header('x-signature'))) {
            return response()->json(['message' => 'Invalid signature'], 400);
        }

        $event = json_decode($body, true) ?: [];
        $intent = $event['data']['object'] ?? [];
        if (($event['name'] ?? null) === 'payment_intent.succeeded') {
            // Our order id from the metadata, cross-checked against the intent id we stored
            $order = Order::withoutGlobalScope('store')->find((int) ($intent['metadata']['order_id'] ?? 0));
            if ($order && $order->payment_gateway === 'airwallex' && $order->payment_reference === ($intent['id'] ?? null)) {
                $airwallex->applyIntent($order, $intent);
            }
        }

        return response()->json(['received' => true]);
    }
}
