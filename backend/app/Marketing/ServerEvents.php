<?php

namespace App\Marketing;

use App\Models\Order;
use App\Models\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Server-side purchase events: Meta Conversions API and TikTok Events API.
 *
 * Ad blockers and iOS privacy settings drop many browser pixel events; sending the purchase from the
 * server too keeps ad reporting and optimisation accurate. The event id ("order-#1001") matches the
 * one the browser pixel sends, so each platform counts the purchase once.
 * Personal data is SHA-256 hashed as both platforms require. Access tokens are stored encrypted.
 */
class ServerEvents
{
    public const META_API = 'https://graph.facebook.com/v19.0';

    public const TIKTOK_API = 'https://business-api.tiktok.com/open_api/v1.3/event/track/';

    /** stores.settings['server_events'] = {meta_token, tiktok_token (encrypted), test_code} */
    public static function settings(Store $store): array
    {
        $s = $store->settings['server_events'] ?? [];
        $dec = fn ($v) => $v ? rescue(fn () => Crypt::decryptString($v), null, false) : null;

        return ['meta_token' => $dec($s['meta_token'] ?? null), 'tiktok_token' => $dec($s['tiktok_token'] ?? null), 'test_code' => $s['test_code'] ?? null];
    }

    /** Empty string = keep, null = remove. */
    public static function save(Store $store, array $data): void
    {
        $old = $store->settings['server_events'] ?? [];
        $enc = function (string $key) use ($data, $old) {
            if (! array_key_exists($key, $data) || $data[$key] === '') {
                return $old[$key] ?? null;
            }

            return $data[$key] === null ? null : Crypt::encryptString(trim($data[$key]));
        };
        $store->settings = [...($store->settings ?? []), 'server_events' => [
            'meta_token' => $enc('meta_token'), 'tiktok_token' => $enc('tiktok_token'),
            'test_code' => isset($data['test_code']) && trim((string) $data['test_code']) !== '' ? mb_substr(trim($data['test_code']), 0, 40) : null,
        ]];
        $store->save();
    }

    /** Is any platform set up (pixel id + token)? Cheap check before queueing a job. */
    public static function configured(int $storeId): bool
    {
        $store = Store::find($storeId);
        if (! $store) {
            return false;
        }
        $p = Tracking::for($store);
        $s = $store->settings['server_events'] ?? [];

        return ($p->get('meta') && ! empty($s['meta_token'])) || ($p->get('tiktok') && ! empty($s['tiktok_token']));
    }

    /** Last result per platform, for the settings page. */
    public static function status(Store $store): array
    {
        return ['meta' => Cache::get("server-events:{$store->id}:meta"), 'tiktok' => Cache::get("server-events:{$store->id}:tiktok")];
    }

    public function purchase(Order $order, bool $test = false): array
    {
        $store = Store::find($order->store_id);
        $pixels = Tracking::for($store);
        $s = self::settings($store);
        $results = [];
        try {
            if ($pixels->get('meta') && $s['meta_token']) {
                $results['meta'] = $this->send($store, 'meta', fn () => Http::timeout(10)->asJson()
                    ->post(self::META_API.'/'.$pixels->get('meta').'/events?access_token='.urlencode($s['meta_token']), $this->meta($order, $store, $test ? $s['test_code'] : ($s['test_code'] ?: null))));
            }
            if ($pixels->get('tiktok') && $s['tiktok_token']) {
                $results['tiktok'] = $this->send($store, 'tiktok', fn () => Http::timeout(10)->asJson()->withHeaders(['Access-Token' => $s['tiktok_token']])
                    ->post(self::TIKTOK_API, $this->tiktok($order, $store, $pixels->get('tiktok'), $s['test_code'])));
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $results;
    }

    private function send(Store $store, string $platform, callable $call): array
    {
        try {
            $res = $call();
            $body = $res->json() ?? [];
            // TikTok answers 200 with a non-zero "code" on errors
            $ok = $res->successful() && ($platform !== 'tiktok' || (int) ($body['code'] ?? 0) === 0);
            $error = $ok ? null : mb_substr((string) ($body['error']['message'] ?? $body['message'] ?? $res->status()), 0, 300);
        } catch (\Throwable $e) {
            $ok = false;
            $error = mb_substr($e->getMessage(), 0, 300);
        }
        $result = ['ok' => $ok, 'error' => $error, 'at' => now()->toIso8601String()];
        Cache::put("server-events:{$store->id}:{$platform}", $result, now()->addDays(30));

        return $result;
    }

    private function meta(Order $order, Store $store, ?string $testCode): array
    {
        $a = $order->shipping_address ?? [];
        $user = array_filter([
            'em' => [$this->hash($order->email)],
            'ph' => ($p = $this->phone($a['phone'] ?? null)) ? [$this->hash($p)] : null,
            'fn' => ! empty($a['first_name']) ? [$this->hash($a['first_name'])] : null,
            'ln' => ! empty($a['last_name']) ? [$this->hash($a['last_name'])] : null,
            'ct' => ! empty($a['city']) ? [$this->hash(str_replace(' ', '', $a['city']))] : null,
            'zp' => ! empty($a['zip']) ? [$this->hash(str_replace(' ', '', $a['zip']))] : null,
            'country' => ! empty($a['country']) ? [$this->hash($a['country'])] : null,
            'external_id' => $order->customer_id ? [$this->hash((string) $order->customer_id)] : null,
        ]);

        return array_filter([
            'data' => [[
                'event_name' => 'Purchase',
                'event_time' => ($order->paid_at ?? now())->timestamp,
                'event_id' => 'order-'.$order->name(),
                'action_source' => 'website',
                'event_source_url' => $store->storefrontUrl().'/orders/'.$order->token,
                'user_data' => $user,
                'custom_data' => [
                    'currency' => $order->currency,
                    'value' => round(($order->total + (int) $order->gift_card_total) / 100, 2),
                    'order_id' => $order->name(),
                    'content_type' => 'product',
                    'contents' => $order->items->map(fn ($i) => ['id' => (string) ($i->variant_id ?? $i->product_id), 'quantity' => $i->quantity, 'item_price' => round($i->price / 100, 2)])->values()->all(),
                    'num_items' => (int) $order->items->sum('quantity'),
                ],
            ]],
            'test_event_code' => $testCode,
        ]);
    }

    private function tiktok(Order $order, Store $store, string $pixel, ?string $testCode): array
    {
        $a = $order->shipping_address ?? [];

        return array_filter([
            'event_source' => 'web',
            'event_source_id' => $pixel,
            'test_event_code' => $testCode,
            'data' => [[
                'event' => 'CompletePayment',
                'event_time' => ($order->paid_at ?? now())->timestamp,
                'event_id' => 'order-'.$order->name(),
                'user' => array_filter([
                    'email' => $this->hash($order->email),
                    'phone' => ($p = $this->phone($a['phone'] ?? null)) ? $this->hash('+'.$p) : null,
                    'external_id' => $order->customer_id ? $this->hash((string) $order->customer_id) : null,
                ]),
                'page' => ['url' => $store->storefrontUrl().'/orders/'.$order->token],
                'properties' => [
                    'currency' => $order->currency,
                    'value' => round(($order->total + (int) $order->gift_card_total) / 100, 2),
                    'order_id' => $order->name(),
                    'content_type' => 'product',
                    'contents' => $order->items->map(fn ($i) => ['content_id' => (string) ($i->variant_id ?? $i->product_id), 'content_name' => $i->title, 'quantity' => $i->quantity, 'price' => round($i->price / 100, 2)])->values()->all(),
                ],
            ]],
        ]);
    }

    private function hash(string $v): string
    {
        return hash('sha256', mb_strtolower(trim($v)));
    }

    /** Digits only, as both APIs expect (country code included when the buyer typed it). */
    private function phone(?string $v): ?string
    {
        $d = preg_replace('/\D/', '', (string) $v);

        return strlen($d) >= 7 ? $d : null;
    }
}
