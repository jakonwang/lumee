<?php

namespace App\Payments;

use App\Models\Store;

/**
 * Per-store payment settings, kept in stores.settings['payments']:
 *   stripe: on/off (only offered when the platform has Stripe keys)
 *   paypal: on/off (only offered when the platform has PayPal keys)
 *   manual: on/off, the name buyers see ("Bank transfer", "Cash on delivery"…) and the
 *           instructions/bank details shown at checkout, on the order page and in the confirmation email
 *   test:   on/off (only offered when the platform enables the dev test gateway)
 */
final class PaymentSettings
{
    public const DEFAULTS = [
        'stripe' => ['enabled' => true, 'methods' => []],
        'paypal' => ['enabled' => true],
        'airwallex' => ['enabled' => true],
        'razorpay' => ['enabled' => true],
        'manual' => ['enabled' => true, 'label' => 'Bank transfer', 'instructions' => ''],
        'test' => ['enabled' => true],
    ];

    /**
     * Extra payment methods offered through Stripe Checkout next to cards, with the currencies
     * each one takes (https://docs.stripe.com/payments/payment-methods/payment-method-support).
     * A method only appears when the store's currency is supported.
     */
    public const STRIPE_METHODS = [
        'klarna' => ['Klarna', ['AUD', 'CAD', 'CHF', 'CZK', 'DKK', 'EUR', 'GBP', 'NOK', 'NZD', 'PLN', 'SEK', 'USD']],
        'afterpay_clearpay' => ['Afterpay / Clearpay', ['AUD', 'CAD', 'GBP', 'NZD', 'USD']],
        'ideal' => ['iDEAL', ['EUR']],
        'bancontact' => ['Bancontact', ['EUR']],
        'alipay' => ['Alipay', ['AUD', 'CAD', 'CNY', 'EUR', 'GBP', 'HKD', 'JPY', 'NZD', 'SGD', 'USD']],
        'pix' => ['Pix', ['BRL']],
        'wechat_pay' => ['WeChat Pay', ['AUD', 'CAD', 'CHF', 'CNY', 'DKK', 'EUR', 'GBP', 'HKD', 'JPY', 'NOK', 'SEK', 'SGD', 'USD']],
    ];

    public function __construct(private Store $store) {}

    /** Stripe methods switched on for this store that work in its currency. @return string[] */
    public function stripeMethods(): array
    {
        $currency = $this->store->currency;

        return array_values(array_filter((array) ($this->all()['stripe']['methods'] ?? []),
            fn ($m) => isset(self::STRIPE_METHODS[$m]) && in_array($currency, self::STRIPE_METHODS[$m][1], true)));
    }

    public static function for(Store $store): self
    {
        return new self($store);
    }

    public function all(): array
    {
        $saved = $this->store->settings['payments'] ?? [];
        $out = [];
        foreach (self::DEFAULTS as $key => $defaults) {
            $out[$key] = array_merge($defaults, array_intersect_key($saved[$key] ?? [], $defaults));
            if ($key === 'stripe') {
                $out[$key]['methods'] = array_values(array_intersect(array_keys(self::STRIPE_METHODS), (array) $out[$key]['methods']));
            }
        }
        $out['manual']['label'] = trim((string) $out['manual']['label']) ?: self::DEFAULTS['manual']['label'];

        return $out;
    }

    public function enabled(string $gateway): bool
    {
        return (bool) ($this->all()[$gateway]['enabled'] ?? false);
    }

    public function manualLabel(): string
    {
        return app(\App\I18n\Localization::class)->pick('store', $this->store->id, 'payment_label', $this->all()['manual']['label']);
    }

    public function instructions(): string
    {
        return trim((string) app(\App\I18n\Localization::class)->pick('store', $this->store->id, 'payment_instructions', $this->all()['manual']['instructions']));
    }

    public function save(array $data): void
    {
        $settings = $this->store->settings ?? [];
        $settings['payments'] = [
            'stripe' => [
                'enabled' => (bool) ($data['stripe']['enabled'] ?? false),
                'methods' => array_values(array_intersect(array_keys(self::STRIPE_METHODS), (array) ($data['stripe']['methods'] ?? []))),
            ],
            'paypal' => ['enabled' => (bool) ($data['paypal']['enabled'] ?? false)],
            'airwallex' => ['enabled' => (bool) ($data['airwallex']['enabled'] ?? false)],
            'razorpay' => ['enabled' => (bool) ($data['razorpay']['enabled'] ?? false)],
            'manual' => [
                'enabled' => (bool) ($data['manual']['enabled'] ?? false),
                'label' => trim((string) ($data['manual']['label'] ?? '')) ?: self::DEFAULTS['manual']['label'],
                'instructions' => trim((string) ($data['manual']['instructions'] ?? '')),
            ],
            'test' => ['enabled' => (bool) ($data['test']['enabled'] ?? false)],
        ];
        $this->store->settings = $settings;
        $this->store->save();
    }
}
