<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\I18n\Localization;
use App\Marketing\Tracking;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Analytics & ad pixels (GA4, Meta, TikTok), cookie consent, and the Google Merchant feed URLs. */
class TrackingController extends Controller
{
    public function show(CurrentStore $current): JsonResponse
    {
        return response()->json($this->payload($current));
    }

    public function update(Request $request, CurrentStore $current): JsonResponse
    {
        foreach (['ga4', 'tiktok'] as $k) {
            $request->merge([$k => Tracking::clean($request->input($k), true)]);
        }
        $request->merge(['meta' => Tracking::clean($request->input('meta'))]);
        $request->validate([
            'ga4' => ['nullable', 'regex:'.Tracking::PATTERNS['ga4']],
            'meta' => ['nullable', 'regex:'.Tracking::PATTERNS['meta']],
            'tiktok' => ['nullable', 'regex:'.Tracking::PATTERNS['tiktok']],
            'consent' => ['required', 'boolean'],
        ], [
            'ga4.regex' => __('GA4 衡量 ID 格式不正确，应以 G- 开头，例如 G-AB12CD34EF'),
            'meta.regex' => __('Meta Pixel ID 应为一串数字'),
            'tiktok.regex' => __('TikTok Pixel ID 格式不正确，应为字母和数字组成的代码'),
        ]);

        Tracking::save($current->getOrFail(), $request->only('ga4', 'meta', 'tiktok', 'consent'));

        return response()->json($this->payload($current));
    }

    /** Conversions API tokens (write-only: the page only learns whether one is saved). */
    public function updateServerEvents(Request $request, CurrentStore $current): JsonResponse
    {
        $data = $request->validate([
            'meta_token' => ['nullable', 'string', 'max:500'],
            'tiktok_token' => ['nullable', 'string', 'max:500'],
            'test_code' => ['nullable', 'string', 'max:40'],
            'remove' => ['nullable', 'array'],
            'remove.*' => ['in:meta_token,tiktok_token'],
        ]);
        foreach ($data['remove'] ?? [] as $k) {
            $data[$k] = null;
        }
        foreach (['meta_token', 'tiktok_token'] as $k) {
            if (! in_array($k, $data['remove'] ?? [], true)) {
                $data[$k] = $data[$k] ?? '';   // empty = keep the saved one
            }
        }
        \App\Marketing\ServerEvents::save($current->getOrFail(), $data);

        return response()->json($this->payload($current));
    }

    /** Send the latest paid order (or a sample) with the test code, to check the setup in Events Manager. */
    public function testServerEvents(CurrentStore $current, \App\Marketing\ServerEvents $events): JsonResponse
    {
        $store = $current->getOrFail();
        $order = \App\Models\Order::with('items')->whereNotNull('paid_at')->latest('id')->first();
        if (! $order) {
            $order = (new \App\Models\Order)->forceFill([
                'store_id' => $store->id, 'number' => 1000, 'token' => (string) \Illuminate\Support\Str::uuid(), 'email' => 'test@example.com',
                'currency' => $store->currency, 'total' => 1000, 'gift_card_total' => 0, 'shipping_address' => ['country' => 'US'], 'paid_at' => now(),
            ]);
            $order->setRelation('items', collect([(new \App\Models\OrderItem)->forceFill(['product_id' => 0, 'variant_id' => 0, 'title' => 'Test item', 'price' => 1000, 'quantity' => 1])]));
        }
        $results = $events->purchase($order, true);
        abort_if(! $results, 422, __('请先填写 Pixel ID 和对应的访问令牌'));

        return response()->json(['results' => $results, ...$this->payload($current)]);
    }

    private function payload(CurrentStore $current): array
    {
        $store = $current->getOrFail()->fresh();
        $base = $store->storefrontUrl();
        $languages = Localization::enabled($store);

        return [
            'settings' => Tracking::for($store)->all(),
            'server_events' => (function () use ($store) {
                $s = \App\Marketing\ServerEvents::settings($store);

                return [
                    'meta_token_set' => (bool) $s['meta_token'], 'tiktok_token_set' => (bool) $s['tiktok_token'],
                    'test_code' => $s['test_code'], 'status' => \App\Marketing\ServerEvents::status($store),
                ];
            })(),
            // One row per site: each language of the default site, then each market and its languages
            'feeds' => collect($languages)->map(fn ($code, $i) => self::feedRow($base.($i === 0 ? '' : '/'.$code), $code, config("lumee.languages.$code", $code), null, $store->baseCurrency()))
                ->concat(\App\Markets\MarketContext::live($store)->flatMap(fn ($m) => collect($m->languages())->map(fn ($code) => self::feedRow(
                    $base.$m->prefix($code), $code, $m->name.' · '.config("lumee.languages.$code", $code), $m->id, $m->currency))))
                ->values(),
        ];
    }

    /** Feed links of one site: Google Merchant Center, Meta catalog, TikTok catalog. */
    private static function feedRow(string $site, string $locale, string $name, ?int $marketId, string $currency): array
    {
        return [
            'locale' => $locale, 'name' => $name, 'market_id' => $marketId, 'currency' => $currency,
            'url' => $site.'/feeds/google.xml',
            'urls' => ['google' => $site.'/feeds/google.xml', 'meta' => $site.'/feeds/meta.xml', 'tiktok' => $site.'/feeds/tiktok.csv'],
        ];
    }
}
