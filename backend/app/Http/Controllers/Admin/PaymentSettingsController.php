<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Payments\GatewayRegistry;
use App\Payments\PaymentSettings;
use App\Payments\StripeConnect;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PaymentSettingsController extends Controller
{
    public function show(CurrentStore $current): JsonResponse
    {
        return response()->json($this->payload($current));
    }

    public function update(Request $request, CurrentStore $current): JsonResponse
    {
        $data = $request->validate([
            'stripe.enabled' => ['boolean'],
            'stripe.methods' => ['nullable', 'array'],
            'stripe.methods.*' => ['string', \Illuminate\Validation\Rule::in(array_keys(PaymentSettings::STRIPE_METHODS))],
            'paypal.enabled' => ['boolean'],
            'airwallex.enabled' => ['boolean'],
            'razorpay.enabled' => ['boolean'],
            'manual.enabled' => ['boolean'],
            'manual.label' => ['nullable', 'string', 'max:60'],
            'manual.instructions' => ['nullable', 'string', 'max:2000'],
            'test.enabled' => ['boolean'],
        ]);

        // Buyers must always have at least one way to pay (that this store can actually use)
        $registry = app(GatewayRegistry::class);
        $store = $current->getOrFail();
        $usable = $registry->configured()->filter(fn ($g) => $registry->usableBy($g, $store))->keys();
        if (! $usable->contains(fn ($key) => (bool) ($data[$key]['enabled'] ?? false))) {
            throw ValidationException::withMessages(['manual.enabled' => __('至少需要开启一种收款方式，否则买家无法下单')]);
        }
        if (($data['manual']['enabled'] ?? false) && trim((string) ($data['manual']['instructions'] ?? '')) === '') {
            throw ValidationException::withMessages(['manual.instructions' => __('开启线下付款时，请填写付款说明（例如收款账户信息）')]);
        }

        PaymentSettings::for($current->getOrFail())->save($data);

        return response()->json($this->payload($current));
    }

    private function payload(CurrentStore $current): array
    {
        $configured = app(GatewayRegistry::class)->configured();

        return [
            'settings' => PaymentSettings::for($current->getOrFail())->all(),
            // Which gateways the platform has set up (Stripe needs platform keys; test is dev-only)
            'available' => [
                'stripe' => $configured->has('stripe'),
                'paypal' => $configured->has('paypal'),
                'airwallex' => $configured->has('airwallex'),
                'razorpay' => $configured->has('razorpay'),
                'manual' => true,
                'test' => $configured->has('test'),
            ],
            // PayPal doesn't take every store currency (e.g. AED, and CNY outside China)
            'paypal_currency_ok' => app(\App\Payments\PayPalGateway::class)->supportsCurrency($current->getOrFail()->currency),
            'currency' => $current->getOrFail()->currency,
            'razorpay_currency_ok' => $current->getOrFail()->currency === 'INR',
            'stripe_methods' => collect(PaymentSettings::STRIPE_METHODS)->map(fn ($m, $key) => [
                // Usable when the store currency or any market's currency takes it (OXXO in a Mexico market…)
                'key' => $key, 'name' => $m[0], 'currencies' => $m[1],
                'supported' => (bool) array_intersect([$current->getOrFail()->baseCurrency(), ...\App\Models\Market::pluck('currency')->all()], $m[1]),
            ])->values(),
            'stripe_connect' => app(StripeConnect::class)->summary($current->getOrFail()),
        ];
    }

    /** Start (or continue) Stripe onboarding: returns the Stripe-hosted URL to send the merchant to. */
    public function stripeConnect(Request $request, CurrentStore $current, StripeConnect $connect): JsonResponse
    {
        $data = $request->validate(['country' => ['nullable', 'string', 'size:2']]);
        $this->requireConnect($connect);
        $admin = rtrim((string) config('lumee.admin_url'), '/').'/settings/payments';
        try {
            $url = $connect->onboardingUrl($current->getOrFail(), $data['country'] ?? null, $admin.'?stripe=return', $admin.'?stripe=refresh');
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['country' => $e->getMessage()]);
        }

        return response()->json(['url' => $url]);
    }

    /** Re-read the account (the merchant just came back from Stripe). */
    public function stripeRefresh(CurrentStore $current, StripeConnect $connect): JsonResponse
    {
        $this->requireConnect($connect);
        try {
            $connect->refresh($current->getOrFail());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['stripe' => $e->getMessage()]);
        }

        return response()->json($this->payload($current));
    }

    public function stripeDashboard(CurrentStore $current, StripeConnect $connect): JsonResponse
    {
        $this->requireConnect($connect);
        try {
            return response()->json(['url' => $connect->dashboardUrl($current->getOrFail())]);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['stripe' => $e->getMessage()]);
        }
    }

    public function stripeDisconnect(CurrentStore $current, StripeConnect $connect): JsonResponse
    {
        $this->requireConnect($connect);
        $connect->disconnect($current->getOrFail());

        return response()->json($this->payload($current));
    }

    private function requireConnect(StripeConnect $connect): void
    {
        if (! $connect->enabled()) {
            throw ValidationException::withMessages(['stripe' => __('平台没有开启 Stripe Connect')]);
        }
    }
}
