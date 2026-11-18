<?php

namespace App\Notifications;

use App\Jobs\SendSms;
use App\Models\Order;
use App\Models\SmsMessage;
use App\Models\Store;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * SMS order updates through the merchant's own Twilio account (order confirmed, order shipped),
 * in the buyer's language. One message per order and type; sent by a queued job.
 * stores.settings['sms'] = {enabled, account_sid, auth_token (encrypted), from, events: {type: bool}}
 */
class Sms
{
    public const TYPES = ['order_confirmation', 'shipping_confirmation'];

    /** Calling codes for numbers typed without "+" (the shipping country decides). */
    private const CALLING = [
        'US' => '1', 'CA' => '1', 'GB' => '44', 'IE' => '353', 'AU' => '61', 'NZ' => '64', 'DE' => '49', 'FR' => '33', 'ES' => '34',
        'IT' => '39', 'NL' => '31', 'BE' => '32', 'AT' => '43', 'CH' => '41', 'SE' => '46', 'NO' => '47', 'DK' => '45', 'FI' => '358',
        'PL' => '48', 'PT' => '351', 'CZ' => '420', 'GR' => '30', 'JP' => '81', 'KR' => '82', 'CN' => '86', 'HK' => '852', 'TW' => '886',
        'SG' => '65', 'MY' => '60', 'TH' => '66', 'PH' => '63', 'ID' => '62', 'VN' => '84', 'IN' => '91', 'AE' => '971', 'SA' => '966',
        'IL' => '972', 'TR' => '90', 'ZA' => '27', 'BR' => '55', 'MX' => '52', 'AR' => '54', 'CL' => '56', 'CO' => '57',
    ];

    public static function settings(Store $store): array
    {
        $s = $store->settings['sms'] ?? [];

        return [
            'enabled' => (bool) ($s['enabled'] ?? false),
            'account_sid' => $s['account_sid'] ?? null,
            'auth_token' => ! empty($s['auth_token']) ? rescue(fn () => Crypt::decryptString($s['auth_token']), null, false) : null,
            'from' => $s['from'] ?? null,
            'events' => array_replace(['order_confirmation' => false, 'shipping_confirmation' => true], $s['events'] ?? []),
        ];
    }

    public static function ready(Store $store): bool
    {
        $s = self::settings($store);

        return $s['enabled'] && $s['account_sid'] && $s['auth_token'] && $s['from'];
    }

    /** Queue the SMS for an order event if the store sends it and the order has a usable phone. */
    public function forOrder(Order $order, string $type): ?SmsMessage
    {
        $store = Store::find($order->store_id);
        if (! $store || ! in_array($type, self::TYPES, true) || ! self::ready($store) || ! self::settings($store)['events'][$type]) {
            return null;
        }
        $to = self::e164($order->shipping_address['phone'] ?? null, $order->shipping_address['country'] ?? null);
        if (! $to || SmsMessage::withoutGlobalScope('store')->where('order_id', $order->id)->where('type', $type)->exists()) {
            return null;
        }

        $locale = $order->locale ?: \App\I18n\Localization::storeDefault($store);
        $url = $store->storefrontUrl().OrderMail::localePrefix($order->locale, $store)."/orders/{$order->token}";
        $body = app(\App\I18n\Localization::class)->within($store, $locale, fn () => $type === 'shipping_confirmation'
            ? __(':store: your order :order is on its way.', ['store' => $store->name, 'order' => $order->name()])
                .($order->tracking_number ? ' '.__('Tracking: :number', ['number' => $order->tracking_number]) : '').' '.$url
            : __(':store: thanks for your order :order! We will text you when it ships.', ['store' => $store->name, 'order' => $order->name()]).' '.$url);

        $msg = SmsMessage::withoutGlobalScope('store')->forceCreate([
            'store_id' => $store->id, 'order_id' => $order->id, 'type' => $type, 'to' => $to, 'body' => mb_substr($body, 0, 640), 'status' => 'queued',
        ]);
        SendSms::dispatch($msg->id);

        return $msg;
    }

    /** Send one message through Twilio and record the result. */
    public function deliver(SmsMessage $msg): void
    {
        $store = Store::find($msg->store_id);
        $s = self::settings($store);
        if (! $s['account_sid'] || ! $s['auth_token'] || ! $s['from']) {
            $msg->forceFill(['status' => 'failed', 'error' => 'SMS is not set up'])->save();

            return;
        }
        $res = Http::asForm()->timeout(15)->withBasicAuth($s['account_sid'], $s['auth_token'])
            ->post('https://api.twilio.com/2010-04-01/Accounts/'.rawurlencode($s['account_sid']).'/Messages.json', [
                'To' => $msg->to, 'From' => $s['from'], 'Body' => $msg->body,
            ]);
        $msg->forceFill($res->successful()
            ? ['status' => 'sent', 'provider_id' => $res->json('sid'), 'error' => null]
            : ['status' => 'failed', 'error' => mb_substr((string) ($res->json('message') ?? $res->status()), 0, 500)])->save();
    }

    /** "+447700900123" from "+44 7700 900123", "0044…", or "7700 900123" with the shipping country. */
    public static function e164(?string $phone, ?string $country): ?string
    {
        $phone = trim((string) $phone);
        if ($phone === '') {
            return null;
        }
        $digits = preg_replace('/\D/', '', $phone);
        if (str_starts_with($phone, '+')) {
            $n = $digits;
        } elseif (str_starts_with($digits, '00')) {
            $n = substr($digits, 2);
        } elseif ($country && isset(self::CALLING[$country])) {
            $code = self::CALLING[$country];
            $local = ltrim($digits, '0');
            $n = str_starts_with($digits, $code) && strlen($digits) > 10 ? $digits : $code.$local;
        } else {
            return null;
        }

        return strlen($n) >= 8 && strlen($n) <= 15 ? '+'.$n : null;
    }
}
