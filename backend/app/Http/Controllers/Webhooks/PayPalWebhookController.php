<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Payments\PayPalGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PayPal webhooks — subscribe to CHECKOUT.ORDER.APPROVED and PAYMENT.CAPTURE.COMPLETED.
 * Covers buyers who approve but never come back to the store, and captures that complete later.
 */
class PayPalWebhookController extends Controller
{
    public function __invoke(Request $request, PayPalGateway $paypal): JsonResponse
    {
        $headers = collect($request->headers->all())->map(fn ($v) => $v[0] ?? null)->all();
        $event = $paypal->verifyWebhook($headers, $request->getContent());
        if (! $event) {
            return response()->json(['message' => 'Invalid signature'], 400);
        }

        $resource = $event['resource'] ?? [];

        switch ($event['event_type'] ?? '') {
            case 'CHECKOUT.ORDER.APPROVED':
                // No store context on the webhook host: find the order by its PayPal order id (unique)
                $order = $this->find($resource['purchase_units'][0]['custom_id'] ?? null, $resource['id'] ?? null);
                if ($order) {
                    $paypal->capture($order);
                }
                break;

            case 'PAYMENT.CAPTURE.COMPLETED':
                $paypalOrderId = $resource['supplementary_data']['related_ids']['order_id'] ?? null;
                $order = $this->find($resource['custom_id'] ?? null, $paypalOrderId)
                    ?? $this->find($resource['custom_id'] ?? null, $resource['id'] ?? null);
                if ($order) {
                    $paypal->applyCapture($order, $resource);
                }
                break;
        }

        return response()->json(['received' => true]);
    }

    /** Our order id comes from custom_id, cross-checked against the reference we stored so a forged id can't mark another order paid. */
    private function find(?string $orderId, ?string $reference): ?Order
    {
        if (! $orderId || ! $reference) {
            return null;
        }
        $order = Order::withoutGlobalScope('store')->find((int) $orderId);

        return $order && $order->payment_gateway === 'paypal' && $order->payment_reference === $reference ? $order : null;
    }
}
