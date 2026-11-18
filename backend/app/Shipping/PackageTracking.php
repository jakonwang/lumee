<?php

namespace App\Shipping;

use App\Models\Order;
use App\Notifications\OrderNotifier;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Package tracking through 17TRACK (API v2.2, https://api.17track.net/en/doc).
 *
 *  - register:  when an order ships with a tracking number (carrier auto-detected by 17TRACK)
 *  - webhook:   17TRACK pushes TRACKING_UPDATED to /webhooks/17track (signed with the API key)
 *  - sync:      hourly fallback for orders the webhook hasn't updated (lumee:tracking-sync)
 *
 * The order keeps the latest status, the carrier name and up to 50 events; "Delivered" sets
 * delivered_at and sends the delivery email. Without SEVENTEEN_TRACK_KEY nothing is called
 * and shoppers just get the tracking link, as before.
 */
class PackageTracking
{
    public const BASE = 'https://api.17track.net/track/v2.2';

    /** 17TRACK main statuses, in the order a parcel normally goes through them. */
    public const STATUSES = ['NotFound', 'InfoReceived', 'InTransit', 'AvailableForPickup', 'OutForDelivery', 'DeliveryFailure', 'Delivered', 'Exception', 'Expired'];

    /** Stop checking parcels that shipped longer ago than this. */
    public const MAX_AGE_DAYS = 60;

    public function enabled(): bool
    {
        return filled(config('services.seventeen_track.key'));
    }

    /** Start tracking an order's parcel. Never throws: shipping must not fail because tracking did. */
    public function register(Order $order): bool
    {
        if (! $this->enabled() || ! $order->tracking_number) {
            return false;
        }
        try {
            $res = $this->api()->post(self::BASE.'/register', [['number' => $order->tracking_number, 'tag' => 'order-'.$order->id]]);
            $rejected = $res->json('data.rejected.0.error.code');
            // -18019901: already registered (e.g. same number re-entered) — fine
            if ($res->failed() || ($res->json('code') !== 0) || ($rejected && $rejected !== -18019901)) {
                report(new \RuntimeException('17TRACK register failed: '.$res->body()));

                return false;
            }
            $order->forceFill(['tracking_registered_at' => now(), 'tracking_status' => $order->tracking_status ?? 'NotFound'])->save();

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /** New tracking number: forget the old parcel's progress and track the new one. */
    public function reset(Order $order): void
    {
        $order->forceFill([
            'tracking_status' => null, 'tracking_carrier' => null, 'tracking_events' => null,
            'tracking_registered_at' => null, 'tracking_synced_at' => null, 'delivered_at' => null,
        ])->save();
        $this->register($order);
    }

    /** Pull the latest info for a batch of orders (17TRACK takes up to 40 numbers per call). */
    public function sync(Collection $orders): int
    {
        if (! $this->enabled()) {
            return 0;
        }
        $updated = 0;
        foreach ($orders->filter(fn ($o) => $o->tracking_number)->chunk(40) as $chunk) {
            try {
                $res = $this->api()->post(self::BASE.'/gettrackinfo', $chunk->map(fn ($o) => ['number' => $o->tracking_number])->values()->all());
            } catch (Throwable $e) {
                report($e);

                continue;
            }
            $byNumber = $chunk->keyBy(fn ($o) => strtoupper($o->tracking_number));
            foreach ((array) $res->json('data.accepted') as $item) {
                $order = $byNumber[strtoupper((string) ($item['number'] ?? ''))] ?? null;
                if ($order && isset($item['track_info'])) {
                    $this->apply($order, $item['track_info']);
                    $updated++;
                }
            }
            // Not known to 17TRACK yet (registration failed earlier): register now
            foreach ((array) $res->json('data.rejected') as $item) {
                $order = $byNumber[strtoupper((string) ($item['number'] ?? ''))] ?? null;
                if ($order && ! $order->tracking_registered_at) {
                    $this->register($order);
                }
            }
        }

        return $updated;
    }

    /** Orders worth checking now: shipped recently, tracking number, not delivered, not checked in the last 3 hours. */
    public function due(): Collection
    {
        return Order::withoutGlobalScope('store')
            ->where('fulfillment_status', 'fulfilled')->whereNotNull('tracking_number')->whereNull('delivered_at')
            ->whereNull('cancelled_at')
            ->where('fulfilled_at', '>=', now()->subDays(self::MAX_AGE_DAYS))
            ->where(fn ($q) => $q->whereNull('tracking_synced_at')->orWhere('tracking_synced_at', '<=', now()->subHours(3)))
            ->where(fn ($q) => $q->whereNull('tracking_status')->orWhereNotIn('tracking_status', ['Delivered', 'Expired']))
            ->orderBy('tracking_synced_at')->limit(400)->get();
    }

    /** Save a 17TRACK track_info block onto the order. */
    public function apply(Order $order, array $info): void
    {
        $status = $info['latest_status']['status'] ?? null;
        $status = in_array($status, self::STATUSES, true) ? $status : ($order->tracking_status ?? 'NotFound');

        $events = collect($info['tracking']['providers'] ?? [])
            ->flatMap(fn ($p) => $p['events'] ?? [])
            ->map(fn ($e) => [
                'time' => $e['time_utc'] ?? $e['time_iso'] ?? null,
                'description' => mb_substr(trim((string) ($e['description'] ?? '')), 0, 300),
                'location' => filled($e['location'] ?? null) ? mb_substr((string) $e['location'], 0, 120) : null,
                'stage' => $e['stage'] ?? null,
            ])
            ->filter(fn ($e) => $e['description'] !== '')
            ->sortByDesc('time')->values()->take(50)->all();

        $previous = $order->tracking_status;
        $order->forceFill([
            'tracking_status' => $status,
            'tracking_carrier' => $info['tracking']['providers'][0]['provider']['name'] ?? $order->tracking_carrier,
            'tracking_events' => $events ?: $order->tracking_events,
            'tracking_synced_at' => now(),
            'tracking_registered_at' => $order->tracking_registered_at ?? now(),
        ]);

        if ($status === 'Delivered' && ! $order->delivered_at) {
            $deliveredAt = collect($events)->first()['time'] ?? null;
            $order->delivered_at = $deliveredAt ? \Illuminate\Support\Carbon::parse($deliveredAt)->min(now()) : now();
            $order->save();
            $order->log('delivered', __('包裹已签收'));
            app(OrderNotifier::class)->send($order, 'delivery_confirmation');

            return;
        }
        $order->save();
        if ($status !== $previous && in_array($status, ['Exception', 'DeliveryFailure'], true)) {
            $order->log('tracking', $status === 'Exception' ? __('物流异常，请联系承运商查看包裹情况') : __('派送失败，承运商会重新派送或联系买家'));
        }
    }

    /** Webhook signature: sha256(body + "/" + key). */
    public static function validSignature(string $body, ?string $sign): bool
    {
        $key = (string) config('services.seventeen_track.key');

        return $key !== '' && $sign && hash_equals(hash('sha256', $body.'/'.$key), strtolower($sign));
    }

    private function api(): PendingRequest
    {
        return Http::withHeaders(['17token' => (string) config('services.seventeen_track.key')])->acceptJson()->asJson()->timeout(15);
    }
}
