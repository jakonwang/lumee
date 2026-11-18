<?php

namespace App\Webhooks;

use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * POST one webhook. Up to 6 attempts (now, +1m, +5m, +30m, +2h, +6h); a delivery that fails
 * every attempt counts as one failure for the subscription, which is switched off after
 * WebhookSubscription::MAX_FAILURES failures in a row.
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const BACKOFF = [60, 300, 1800, 7200, 21600];

    public int $timeout = 30;

    /** $once: tests and manual re-sends — one attempt, no retries, doesn't count against the subscription. */
    public function __construct(public int $deliveryId, public bool $once = false) {}

    public function handle(): void
    {
        $d = WebhookDelivery::withoutGlobalScope('store')->with('subscription')->find($this->deliveryId);
        $sub = $d?->subscription;
        if (! $d || $d->status !== 'pending') {
            return;
        }
        if (! $sub || ! $sub->enabled) {
            $d->forceFill(['status' => 'failed', 'response_body' => 'Subscription disabled'])->save();

            return;
        }

        $body = json_encode($d->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $code = null;
        $response = '';
        try {
            $ips = Webhooks::guard($sub->url);
            $p = parse_url($sub->url);
            $port = $p['port'] ?? (strtolower($p['scheme']) === 'https' ? 443 : 80);
            $res = Http::timeout(10)->withOptions([
                'allow_redirects' => false,
                'curl' => [CURLOPT_RESOLVE => ["{$p['host']}:{$port}:{$ips[0]}"]],   // pin to the checked IP
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'User-Agent' => 'Lumee-Webhooks/1.0',
                'X-Lumee-Topic' => $d->topic,
                'X-Lumee-Event-Id' => $d->event_id,
                'X-Lumee-Store-Id' => (string) $d->store_id,
                'X-Lumee-Hmac-Sha256' => base64_encode(hash_hmac('sha256', $body, $sub->secret, true)),
            ])->withBody($body, 'application/json')->post($sub->url);
            $code = $res->status();
            $response = mb_substr((string) $res->body(), 0, 500);
            $ok = $res->successful();
        } catch (Throwable $e) {
            $ok = false;
            $response = mb_substr($e->getMessage(), 0, 500);
        }

        $attempts = $d->attempts + 1;
        $d->forceFill(['attempts' => $attempts, 'response_code' => $code, 'response_body' => $response]);

        if ($ok) {
            $d->forceFill(['status' => 'success', 'delivered_at' => now()])->save();
            $sub->forceFill(['failure_count' => 0, 'last_success_at' => now()])->save();

            return;
        }

        if ($this->once) {
            $d->forceFill(['status' => 'failed'])->save();

            return;
        }
        if ($attempts <= count(self::BACKOFF)) {
            $d->save();
            self::dispatch($d->id)->delay(now()->addSeconds(self::BACKOFF[$attempts - 1]));

            return;
        }

        // Out of retries
        $d->forceFill(['status' => 'failed'])->save();
        $failures = $sub->failure_count + 1;
        $sub->forceFill(['failure_count' => $failures, 'last_failure_at' => now()]);
        if ($failures >= WebhookSubscription::MAX_FAILURES) {
            $sub->forceFill(['enabled' => false, 'disabled_reason' => "Disabled after {$failures} failed deliveries in a row"]);
        }
        $sub->save();
    }
}
