<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Shipping\PackageTracking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 17TRACK push: {"event":"TRACKING_UPDATED","data":{"number","tag":"order-<id>","track_info":{…}}}, header "sign". */
class TrackingWebhookController extends Controller
{
    public function __invoke(Request $request, PackageTracking $tracking): JsonResponse
    {
        $body = $request->getContent();
        if (! PackageTracking::validSignature($body, $request->header('sign'))) {
            return response()->json(['message' => 'Invalid signature'], 400);
        }

        $payload = json_decode($body, true) ?: [];
        $data = $payload['data'] ?? [];
        if (($payload['event'] ?? null) === 'TRACKING_UPDATED' && isset($data['track_info'])) {
            // The tag names our order; the number must match too, so a stale tag can't touch another parcel
            $id = (int) str_replace('order-', '', (string) ($data['tag'] ?? ''));
            $order = Order::withoutGlobalScope('store')->find($id);
            if ($order && strcasecmp((string) $order->tracking_number, (string) ($data['number'] ?? '')) === 0) {
                $tracking->apply($order, $data['track_info']);
            }
        }

        return response()->json(['received' => true]);
    }
}
