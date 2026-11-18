<?php

namespace App\Payments;

use App\Models\Order;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Razorpay (India): UPI, cards, netbanking and wallets on Razorpay's hosted payment page,
 * through the Payment Links API. INR stores only.
 *
 * The buyer pays on rzp.io and comes back to /checkout/razorpay/return with a signed result
 * (HMAC-SHA256 of link_id|reference_id|status|payment_id with the key secret). We then confirm
 * by fetching the link from the API before marking the order paid.
 * orders.payment_reference holds the payment link id until paid, then the payment id (pay_…),
 * which refunds go against.
 */
class RazorpayGateway implements PaymentGateway
{
    public const BASE = 'https://api.razorpay.com/v1';

    public function key(): string
    {
        return 'razorpay';
    }

    public function label(): string
    {
        return __('UPI, cards & netbanking (Razorpay)');
    }

    public function isAvailable(): bool
    {
        return filled(config('services.razorpay.key_id')) && filled(config('services.razorpay.key_secret'));
    }

    public function supportsCurrency(string $currency): bool
    {
        return strtoupper($currency) === 'INR';
    }

    public function start(Order $order, string $storefrontUrl): ?string
    {
        if (! $this->supportsCurrency($order->currency)) {
            throw new RuntimeException(__('Razorpay only accepts payments in Indian rupees. Please choose another payment method.'));
        }
        $order->loadMissing('store');
        $address = (array) $order->shipping_address;
        $response = $this->api()->post('/payment_links', array_filter([
            'amount' => (int) $order->total,
            'currency' => 'INR',
            'accept_partial' => false,
            'reference_id' => mb_substr($order->token, 0, 40),
            'description' => mb_substr(trim(($order->store?->name ?? '').' '.$order->name()), 0, 255),
            'customer' => array_filter([
                'name' => trim(($address['first_name'] ?? '').' '.($address['last_name'] ?? '')) ?: null,
                'email' => $order->email,
                'contact' => $address['phone'] ?? null,
            ]),
            'notify' => ['sms' => false, 'email' => false],
            'reminder_enable' => false,
            'notes' => ['order_id' => (string) $order->id, 'store_id' => (string) $order->store_id],
            'callback_url' => "{$storefrontUrl}/checkout/razorpay/return?order={$order->token}",
            'callback_method' => 'get',
            'expire_by' => now()->addDay()->timestamp,
        ], fn ($v) => $v !== null));

        if ($response->failed() || ! $response->json('short_url')) {
            report(new RuntimeException('Razorpay payment link failed: '.$response->body()));
            throw new RuntimeException(__('Payment could not be started. Please try again.'));
        }
        $order->update(['payment_reference' => $response->json('id')]);

        return $response->json('short_url');
    }

    /** Signature Razorpay adds to the callback URL. https://razorpay.com/docs/payments/payment-links/apis/#verify-signature */
    public static function validSignature(array $q): bool
    {
        $secret = (string) config('services.razorpay.key_secret');
        $sig = (string) ($q['razorpay_signature'] ?? '');
        if ($secret === '' || $sig === '') {
            return false;
        }
        $payload = implode('|', [
            $q['razorpay_payment_link_id'] ?? '', $q['razorpay_payment_link_reference_id'] ?? '',
            $q['razorpay_payment_link_status'] ?? '', $q['razorpay_payment_id'] ?? '',
        ]);

        return hash_equals(hash_hmac('sha256', $payload, $secret), $sig);
    }

    /**
     * Confirm with the API that the order's link is paid for the full amount, then mark the order paid.
     * Safe to call more than once. Returns true when the order is (now) paid.
     */
    public function confirm(Order $order): bool
    {
        if ($order->isPaid()) {
            return true;
        }
        $linkId = (string) $order->payment_reference;
        if ($order->payment_gateway !== 'razorpay' || ! str_starts_with($linkId, 'plink_')) {
            return false;
        }
        $res = $this->api()->get("/payment_links/{$linkId}");
        if ($res->failed()) {
            report(new RuntimeException('Razorpay link lookup failed: '.$res->body()));

            return false;
        }
        if ($res->json('status') !== 'paid' || (int) $res->json('amount_paid') !== (int) $order->total || strtoupper((string) $res->json('currency')) !== $order->currency) {
            return false;
        }
        $payment = collect((array) $res->json('payments'))->first(fn ($p) => ($p['status'] ?? '') === 'captured');
        $order->markPaid((string) ($payment['payment_id'] ?? $linkId));

        return true;
    }

    public function refundsOnline(): bool
    {
        return true;
    }

    /** https://razorpay.com/docs/api/refunds/create-normal/ */
    public function refund(Order $order, int $amount, string $idempotencyKey): ?string
    {
        $paymentId = (string) $order->payment_reference;
        if (! str_starts_with($paymentId, 'pay_') || $order->paid_at === null) {
            throw new RuntimeException(__('找不到这笔订单的 Razorpay 付款记录，无法自动退款'));
        }
        $res = $this->api()->post("/payments/{$paymentId}/refund", [
            'amount' => $amount,
            'speed' => 'normal',
            'receipt' => mb_substr($idempotencyKey, 0, 40),
            'notes' => ['order' => $order->name()],
        ]);
        if ($res->failed()) {
            report(new RuntimeException('Razorpay refund failed: '.$res->body()));
            throw new RuntimeException(__('Razorpay 退款失败：').($res->json('error.description') ?: __('请稍后重试')));
        }

        return $res->json('id');
    }

    private function api(): PendingRequest
    {
        return Http::baseUrl(self::BASE)->acceptJson()->asJson()->timeout(20)
            ->withBasicAuth((string) config('services.razorpay.key_id'), (string) config('services.razorpay.key_secret'));
    }
}
