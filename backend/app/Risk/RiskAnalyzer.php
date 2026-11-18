<?php

namespace App\Risk;

use App\Models\BlocklistEntry;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Http\Request;

/**
 * Fraud risk for storefront orders. Each signal adds points; the total gives the level
 * (low < 30 ≤ medium < 60 ≤ high). Reasons are kept as codes + values so the admin shows them in
 * the merchant's language. High-risk orders can be held: they can't be fulfilled until reviewed.
 *
 * Signals: IP country ≠ shipping country, disposable email domain, unusually large first order,
 * several orders / several emails from one IP, the same email ordering repeatedly, bulk quantities,
 * an earlier chargeback from this buyer, and Stripe Radar's verdict (from the charge webhook).
 */
class RiskAnalyzer
{
    public const MEDIUM = 30;

    public const HIGH = 60;

    public const DEFAULTS = ['enabled' => true, 'hold_high' => true];

    /** Common throwaway-inbox domains (kept short on purpose; merchants add their own to the blocklist). */
    public const DISPOSABLE = [
        'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', 'sharklasers.com', '10minutemail.com', 'tempmail.com', 'temp-mail.org',
        'yopmail.com', 'trashmail.com', 'getnada.com', 'dispostable.com', 'maildrop.cc', 'throwawaymail.com', 'fakeinbox.com', 'mintemail.com',
        'mohmal.com', 'emailondeck.com', 'tempail.com', 'burnermail.io', 'mailnesia.com',
    ];

    private const GEO_HEADERS = ['CF-IPCountry', 'CloudFront-Viewer-Country', 'X-Country-Code', 'X-Geo-Country', 'Fastly-Geo-Country'];

    public static function settings(Store $store): array
    {
        return array_merge(self::DEFAULTS, array_intersect_key($store->settings['risk'] ?? [], self::DEFAULTS));
    }

    /** Country of the request from a CDN header (null when there's no CDN in front). */
    public static function requestCountry(?Request $request): ?string
    {
        foreach (self::GEO_HEADERS as $h) {
            $v = strtoupper(trim((string) $request?->header($h)));
            if (preg_match('/^[A-Z]{2}$/', $v) && ! in_array($v, ['XX', 'T1', 'ZZ'], true)) {
                return $v;
            }
        }

        return null;
    }

    /** Checkout guard: the blocklist entry that stops this buyer, if any (and count the hit). */
    public function blocked(int $storeId, string $email, ?string $ip): ?BlocklistEntry
    {
        $email = mb_strtolower(trim($email));
        $domain = str_contains($email, '@') ? substr($email, strrpos($email, '@') + 1) : '';
        $entry = BlocklistEntry::withoutGlobalScope('store')->where('store_id', $storeId)
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('type', 'email')->where('value', $email))
                ->orWhere(fn ($w) => $w->where('type', 'domain')->where('value', $domain))
                ->when($ip, fn ($q2) => $q2->orWhere(fn ($w) => $w->where('type', 'ip')->where('value', $ip))))
            ->first();
        $entry?->increment('hits');

        return $entry;
    }

    /** Score a freshly placed order and record IP/country. Returns the level. */
    public function assess(Order $order, ?Request $request = null): string
    {
        $store = Store::find($order->store_id);
        if (! $store || ! self::settings($store)['enabled']) {
            return 'low';
        }
        $order->forceFill(['client_ip' => $request?->ip(), 'client_country' => self::requestCountry($request)]);

        return $this->score($order, $store);
    }

    /** Stripe Radar's outcome for the charge (charge.succeeded webhook). */
    public function radar(Order $order, ?string $level, ?int $score): void
    {
        if (! in_array($level, ['elevated', 'highest'], true)) {
            return;
        }
        $store = Store::find($order->store_id);
        if (! $store || ! self::settings($store)['enabled']) {
            return;
        }
        $reasons = collect($order->risk_reasons ?? [])->reject(fn ($r) => $r['code'] === 'radar')->values()->all();
        $reasons[] = ['code' => 'radar', 'points' => $level === 'highest' ? 60 : 30, 'level' => $level, 'score' => $score];
        $order->forceFill(['risk_reasons' => $reasons]);
        $this->apply($order, $store, $reasons);
    }

    private function score(Order $order, Store $store): string
    {
        $q = fn () => Order::withoutGlobalScope('store')->where('store_id', $order->store_id)->whereKeyNot($order->id);
        $reasons = [];
        $add = function (string $code, int $points, array $data = []) use (&$reasons) {
            $reasons[] = ['code' => $code, 'points' => $points, ...$data];
        };

        $ship = strtoupper((string) ($order->shipping_address['country'] ?? ''));
        if ($order->client_country && $ship !== '' && $order->client_country !== $ship) {
            $add('ip_country_mismatch', 25, ['ip_country' => $order->client_country, 'ship_country' => $ship]);
        }

        $email = mb_strtolower((string) $order->email);
        $domain = substr($email, (int) strrpos($email, '@') + 1);
        if (in_array($domain, self::DISPOSABLE, true)) {
            $add('disposable_email', 30, ['domain' => $domain]);
        }

        $previous = $q()->where('email', $email)->whereNotNull('paid_at')->count();
        if ($previous === 0) {
            $paid = $q()->whereNotNull('paid_at');
            $aov = (int) ($paid->count() >= 10 ? $paid->avg('total') : 0);
            $limit = $aov > 0 ? $aov * 3 : 100000;   // 3× the store's average, or 1,000.00 for a new store
            if ((int) $order->total > $limit) {
                $add('large_first_order', 20, ['total' => (int) $order->total, 'average' => $aov]);
            }
        }

        if ($order->client_ip) {
            $sameIp = $q()->where('client_ip', $order->client_ip)->where('created_at', '>=', now()->subHour())->count();
            if ($sameIp >= 2) {
                $add('ip_velocity', 25, ['count' => $sameIp + 1]);
            }
            $emails = $q()->where('client_ip', $order->client_ip)->where('created_at', '>=', now()->subDay())->distinct()->count('email');
            if ($emails >= 2 && ! $q()->where('client_ip', $order->client_ip)->where('email', $email)->where('created_at', '>=', now()->subDay())->exists()) {
                $add('many_emails_same_ip', 20, ['count' => $emails + 1]);
            }
        }
        $sameEmail = $q()->where('email', $email)->where('created_at', '>=', now()->subHour())->count();
        if ($sameEmail >= 2) {
            $add('email_velocity', 15, ['count' => $sameEmail + 1]);
        }

        $maxQty = (int) $order->items()->max('quantity');
        if ($maxQty >= 10 && ! $order->company_id) {
            $add('bulk_quantity', 10, ['quantity' => $maxQty]);
        }

        if ($q()->where('email', $email)->whereNotNull('dispute')->exists()) {
            $add('previous_chargeback', 40);
        }

        return $this->apply($order, $store, $reasons);
    }

    private function apply(Order $order, Store $store, array $reasons): string
    {
        $score = min(100, array_sum(array_column($reasons, 'points')));
        $level = $score >= self::HIGH ? 'high' : ($score >= self::MEDIUM ? 'medium' : 'low');
        $wasHigh = $order->risk_level === 'high';
        $order->forceFill([
            'risk_score' => $score, 'risk_level' => $level, 'risk_reasons' => $reasons,
            'risk_hold' => $order->risk_hold || ($level === 'high' && self::settings($store)['hold_high'] && $order->fulfillment_status !== 'fulfilled'),
        ])->save();
        if ($level === 'high' && ! $wasHigh) {
            $order->log('risk', __('高风险订单（风险分 :p0），已暂停发货，请核实后再处理', ['p0' => $score]));
        }

        return $level;
    }

    /** Readable reason for the admin (in the merchant's language). */
    public static function describe(array $r, string $currency): string
    {
        $money = fn ($v) => \App\Support\Money::display((int) $v, $currency);

        return match ($r['code']) {
            'ip_country_mismatch' => __('下单 IP 在 :p0，收货地址在 :p1', ['p0' => $r['ip_country'], 'p1' => $r['ship_country']]),
            'disposable_email' => __('使用临时邮箱（:p0）', ['p0' => $r['domain']]),
            'large_first_order' => $r['average'] > 0
                ? __('首次下单金额 :p0，远高于店铺平均 :p1', ['p0' => $money($r['total']), 'p1' => $money($r['average'])])
                : __('首次下单金额较高（:p0）', ['p0' => $money($r['total'])]),
            'ip_velocity' => __('同一 IP 一小时内下了 :p0 单', ['p0' => $r['count']]),
            'many_emails_same_ip' => __('同一 IP 一天内用了 :p0 个不同邮箱下单', ['p0' => $r['count']]),
            'email_velocity' => __('同一邮箱一小时内下了 :p0 单', ['p0' => $r['count']]),
            'bulk_quantity' => __('单个商品购买 :p0 件', ['p0' => $r['quantity']]),
            'previous_chargeback' => __('这个邮箱以前发生过拒付'),
            'radar' => $r['level'] === 'highest' ? __('Stripe Radar 判定为高风险') : __('Stripe Radar 判定为风险偏高'),
            default => $r['code'],
        };
    }
}
