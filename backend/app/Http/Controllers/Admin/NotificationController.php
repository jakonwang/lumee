<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Notifications\NotificationSettings;
use App\Notifications\OrderMail;
use App\Notifications\OrderNotifier;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class NotificationController extends Controller
{
    public function show(CurrentStore $current): JsonResponse
    {
        return response()->json($this->payload($current));
    }

    public function update(Request $request, CurrentStore $current): JsonResponse
    {
        $data = $request->validate([
            'sender_name' => ['required', 'string', 'max:80'],
            'reply_to' => ['required', 'email', 'max:255'],
            'merchant_email' => ['required', 'email', 'max:255'],
            'enabled' => ['required', 'array'],
            'enabled.*' => ['boolean'],
        ], [
            'reply_to.email' => __('回复邮箱格式不正确'),
            'merchant_email.email' => __('新订单提醒邮箱格式不正确'),
        ]);

        NotificationSettings::for($current->getOrFail())->save($data);

        return response()->json($this->payload($current));
    }

    /** Rendered HTML of an email, using the latest real order or a sample one. */
    public function preview(string $type, CurrentStore $current): Response
    {
        $this->assertType($type);
        $store = $current->getOrFail();

        return response((new OrderMail($this->sampleOrder($type), $type, $store))->render())
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** Send a sample of this email to the signed-in merchant. */
    public function test(Request $request, string $type, CurrentStore $current): JsonResponse
    {
        $this->assertType($type);
        $store = $current->getOrFail();
        $to = $request->user()->email;

        Mail::to($to)->send(new OrderMail($this->sampleOrder($type), $type, $store));

        return response()->json(['message' => __('测试邮件已发送到 :p0', ['p0' => $to])]);
    }

    /** Re-send an order email (e.g. customer says they didn't get it). Ignores the on/off switch. */
    public function resend(Order $order, string $type): JsonResponse
    {
        $this->assertType($type);
        $log = app(OrderNotifier::class)->send($order, $type, force: true);

        return response()->json(['data' => $log, 'message' => $log ? __('已加入发送队列') : __('没有可发送的收件人')], $log ? 202 : 422);
    }

    private function payload(CurrentStore $current): array
    {
        return [
            'settings' => NotificationSettings::for($current->getOrFail()->load('owner'))->all(),
            'types' => collect(NotificationSettings::TYPES)->map(fn ($t, $key) => [...$t, 1 => __($t[1]), 2 => __($t[2])])->map(fn ($t, $key) => [
                'key' => $key, 'audience' => $t[0], 'label' => $t[1], 'description' => $t[2],
            ])->values(),
            'from_address' => config('mail.from.address'),
            'mailer' => config('mail.default'),
        ];
    }

    private function assertType(string $type): void
    {
        abort_unless(array_key_exists($type, NotificationSettings::TYPES), 404);
    }

    /** Latest real order (so previews look like the merchant's data), else an in-memory sample. */
    private function sampleOrder(string $type): Order
    {
        $order = Order::with('items')->latest('id')->first();

        if (! $order) {
            $order = new Order([
                'number' => 1001,
                'email' => 'customer@example.com',
                'currency' => app(CurrentStore::class)->getOrFail()->currency,
                'subtotal' => 12900, 'shipping_total' => 990, 'tax_total' => 0, 'total' => 13890,
                'financial_status' => 'paid', 'fulfillment_status' => 'unfulfilled',
                'payment_gateway' => 'stripe',
                'shipping_address' => ['first_name' => 'Emma', 'last_name' => 'Wilson', 'address1' => '221 Baker Street', 'city' => 'London', 'zip' => 'NW1 6XE', 'country' => 'GB'],
                'shipping_method' => ['name' => 'Standard Shipping (7–12 days)'],
            ]);
            $order->token = (string) Str::uuid();
            $order->setRelation('items', collect([new OrderItem([
                'title' => 'Amethyst Butterfly Necklace', 'variant_title' => '18K Rose Gold',
                'price' => 12900, 'quantity' => 1, 'total' => 12900,
            ])]));
        }

        // Show each email in the state it's normally sent in (not saved)
        if ($type === 'delivery_confirmation') {
            $order->delivered_at ??= now();
            $order->financial_status = 'paid';
        }
        if ($type === 'pickup_ready') {
            $order->shipping_method = ['name' => 'Pick up at Soho studio', 'price' => 0, 'type' => 'pickup',
                'pickup' => ['name' => 'Soho studio', 'address' => '12 Greek St, London', 'hours' => 'Mon–Sat 10:00–18:00', 'instructions' => 'Ring the bell at the side door']];
            $order->financial_status = 'paid';
        }
        if ($type === 'shipping_confirmation') {
            $order->tracking_company ??= 'DHL Express';
            $order->tracking_number ??= 'JD014600006281247950';
        }
        if (in_array($type, ['payment_received', 'shipping_confirmation'], true)) {
            $order->financial_status = 'paid';
        }
        if ($type === 'order_cancelled') {
            $order->cancelled_at ??= now();
            $order->cancel_reason ??= 'customer';
        }
        if ($type === 'refund_notification') {
            $order->financial_status = 'refunded';
            $order->refunded_total = $order->refunded_total ?: $order->total;
            $order->setRelation('refunds', collect([new \App\Models\Refund(['amount' => $order->refunded_total, 'reason' => null])]));
        }
        if ($type === 'order_cancelled' && $order->isPaid()) {
            $order->refunded_total = $order->refunded_total ?: $order->total;
        }

        return $order;
    }
}
