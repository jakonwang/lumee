<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * US sales tax by street address through Stripe Tax (state + county + city + special districts).
 * Used for US orders when the store turns it on (Settings › Taxes) and has registered its US tax
 * nexus in Stripe Tax. With Stripe Connect it runs on the store's connected account, where its
 * registrations live. Results are cached briefly; any failure falls back to the store's own rates.
 */
class StripeTax
{
    /**
     * @param  array{zip?:string, city?:string, address1?:string, province?:string}  $address
     * @return array{amount:int, rate:float}|null
     */
    public function calculate(Store $store, int $items, int $shipping, array $address): ?array
    {
        $secret = (string) config('services.stripe.secret');
        $zip = trim((string) ($address['zip'] ?? ''));
        if ($secret === '' || $zip === '' || $items + $shipping <= 0) {
            return null;
        }
        $params = [
            'currency' => strtolower($store->currency),
            'line_items[0][amount]' => max(0, $items),
            'line_items[0][reference]' => 'items',
            'line_items[0][tax_behavior]' => 'exclusive',
            'shipping_cost[amount]' => max(0, $shipping),
            'customer_details[address][country]' => 'US',
            'customer_details[address][postal_code]' => $zip,
            'customer_details[address][state]' => strtoupper(trim((string) ($address['province'] ?? ''))) ?: null,
            'customer_details[address][city]' => trim((string) ($address['city'] ?? '')) ?: null,
            'customer_details[address][line1]' => trim((string) ($address['address1'] ?? '')) ?: null,
            'customer_details[address_source]' => 'shipping',
        ];
        $params = array_filter($params, fn ($v) => $v !== null);
        $account = app(\App\Payments\StripeConnect::class)->enabled() ? $store->stripe_account_id : null;
        $key = 'stripe-tax:'.$store->id.':'.md5(json_encode($params).$account);

        return Cache::remember($key, now()->addMinutes(30), function () use ($params, $secret, $account) {
            try {
                $req = Http::asForm()->withToken($secret)->timeout(8);
                if ($account) {
                    $req = $req->withHeaders(['Stripe-Account' => $account]);
                }
                $res = $req->post('https://api.stripe.com/v1/tax/calculations', $params);
                if ($res->failed()) {
                    report(new \RuntimeException('Stripe Tax: '.$res->body()));

                    return null;
                }
                $amount = (int) $res->json('tax_amount_exclusive');
                $base = (int) $params['line_items[0][amount]'] + (int) $params['shipping_cost[amount]'];

                return ['amount' => $amount, 'rate' => $base > 0 ? round($amount / $base * 100, 4) : 0.0];
            } catch (\Throwable $e) {
                report($e);

                return null;
            }
        });
    }
}
