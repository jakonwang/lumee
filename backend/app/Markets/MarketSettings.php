<?php

namespace App\Markets;

use App\Models\Market;

/**
 * Per-market localization (markets.settings). The market's store design (texts, colours, home page)
 * lives with the theme: App\Themes\MarketThemes.
 *   duties  — how import duties are handled: none | included (in prices) | checkout (charged as a
 *             line at checkout) | on_delivery (buyer pays the carrier; the checkout says so),
 *             with a rate (%) on goods and a duty-free threshold (minor units, market currency).
 *   cod     — cash on delivery: on/off, a fee added at checkout and a maximum order total (0 = none),
 *             both minor units in the market currency.
 *   installments — card installments through Stripe (MXN / BRL markets): on/off, the number of
 *             months advertised, a minimum price (minor units) and whether they're interest-free.
 */
final class MarketSettings
{
    public const DUTY_MODES = ['none', 'included', 'checkout', 'on_delivery'];

    public static function of(Market $m): array
    {
        $s = (array) ($m->settings ?? []);
        $d = (array) ($s['duties'] ?? []);
        $cod = (array) ($s['cod'] ?? []);
        $inst = (array) ($s['installments'] ?? []);

        return [
            'duties' => [
                'mode' => in_array($d['mode'] ?? null, self::DUTY_MODES, true) ? $d['mode'] : 'none',
                'rate' => max(0, min(100, (float) ($d['rate'] ?? 0))),
                'threshold' => max(0, (int) ($d['threshold'] ?? 0)),
            ],
            'cod' => [
                'enabled' => (bool) ($cod['enabled'] ?? false),
                'fee' => max(0, (int) ($cod['fee'] ?? 0)),
                'max' => max(0, (int) ($cod['max'] ?? 0)),
            ],
            'installments' => [
                'enabled' => (bool) ($inst['enabled'] ?? false),
                'months' => in_array((int) ($inst['months'] ?? 0), \App\Payments\Installments::MONTHS, true) ? (int) $inst['months'] : 12,
                'min' => max(0, (int) ($inst['min'] ?? 0)),
                'interest_free' => (bool) ($inst['interest_free'] ?? true),
            ],
            'preset' => $s['preset'] ?? null,
        ];
    }

    /**
     * Import duties on the goods of a bag in the current market (minor units, market currency).
     *
     * @return array{mode: string, amount: int, rate: float}
     */
    public static function duties(int $goods): array
    {
        $market = app(MarketContext::class)->market();
        if (! $market) {
            return ['mode' => 'none', 'amount' => 0, 'rate' => 0.0];
        }
        $d = self::of($market)['duties'];
        $amount = $d['mode'] === 'checkout' && $goods > $d['threshold'] ? (int) round($goods * $d['rate'] / 100) : 0;

        return ['mode' => $d['mode'], 'amount' => $amount, 'rate' => $d['rate']];
    }
}
