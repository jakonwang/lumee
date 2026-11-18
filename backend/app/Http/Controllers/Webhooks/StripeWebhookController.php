<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Payments\StripeGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        // Platform endpoint, or the Connect endpoint ("events on connected accounts") — each has its own secret
        $event = StripeGateway::verifyWebhook($request->getContent(), $request->header('Stripe-Signature'), (string) config('services.stripe.webhook_secret'))
            ?? StripeGateway::verifyWebhook($request->getContent(), $request->header('Stripe-Signature'), (string) config('services.stripe.connect_webhook_secret'));

        if (! $event) {
            return response()->json(['message' => 'Invalid signature'], 400);
        }

        if (str_starts_with($event['type'], 'charge.dispute.')) {
            app(\App\Payments\StripeDisputes::class)->handle($event);

            return response()->json(['received' => true]);
        }
        if ($event['type'] === 'account.updated') {
            app(\App\Payments\StripeConnect::class)->applyAccount($event['data']['object'] ?? []);

            return response()->json(['received' => true]);
        }
        if ($event['type'] === 'account.application.deauthorized' && isset($event['account'])) {
            app(\App\Payments\StripeConnect::class)->deauthorized($event['account']);

            return response()->json(['received' => true]);
        }

        // Platform subscriptions (merchants paying Lumée): Checkout in subscription mode, subscription + invoice events
        $isSubscriptionCheckout = $event['type'] === 'checkout.session.completed' && ($event['data']['object']['mode'] ?? null) === 'subscription';
        if ($isSubscriptionCheckout || str_starts_with($event['type'], 'customer.subscription.') || str_starts_with($event['type'], 'invoice.')) {
            app(\App\Billing\StripeBilling::class)->handle($event);

            return response()->json(['received' => true]);
        }

        if ($event['type'] === 'charge.succeeded') {
            $charge = $event['data']['object'];
            $order = isset($charge['metadata']['order_id']) ? Order::withoutGlobalScope('store')->find($charge['metadata']['order_id']) : null;
            $order ??= isset($charge['payment_intent']) ? Order::withoutGlobalScope('store')->where('payment_reference', $charge['payment_intent'])->first() : null;
            if ($order && $order->payment_gateway === 'stripe') {
                app(\App\Risk\RiskAnalyzer::class)->radar($order, $charge['outcome']['risk_level'] ?? null, isset($charge['outcome']['risk_score']) ? (int) $charge['outcome']['risk_score'] : null);
            }

            return response()->json(['received' => true]);
        }

        if ($event['type'] === 'checkout.session.completed') {
            $session = $event['data']['object'];
            // No store context on the webhook host, so look up by id across stores — then
            // cross-check the session id we stored so a forged order_id can't mark another order paid.
            $order = Order::withoutGlobalScope('store')->find($session['metadata']['order_id'] ?? 0);

            if ($order && $order->payment_reference === $session['id'] && ($session['payment_status'] ?? null) === 'paid') {
                $order->markPaid($session['payment_intent'] ?? $session['id']);
            }
        }

        return response()->json(['received' => true]);
    }
}
