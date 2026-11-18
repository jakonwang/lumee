<?php

namespace App\Webhooks;

use App\Http\Resources\OrderResource;
use App\Http\Resources\ProductDetailResource;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Outgoing webhooks. Events are queued after the database transaction commits, one delivery
 * row per subscription, then POSTed by DeliverWebhook with an HMAC signature:
 *
 *   X-Lumee-Topic: orders/paid            X-Lumee-Event-Id: <uuid>
 *   X-Lumee-Store-Id: 12                  X-Lumee-Hmac-Sha256: base64(hmac_sha256(body, secret))
 *
 * Body: {"id": <event uuid>, "topic", "created_at", "store_id", "data": <same JSON as the REST API>}
 */
class Webhooks
{
    /** @var callable(string): string[] host → IPs (swappable in tests) */
    public static $resolver = null;

    public function dispatch(int $storeId, string $topic, array $data): int
    {
        $subs = WebhookSubscription::withoutGlobalScope('store')->where('store_id', $storeId)->where('enabled', true)->get()
            ->filter(fn (WebhookSubscription $s) => in_array($topic, $s->topics ?? [], true));
        if ($subs->isEmpty()) {
            return 0;
        }
        $eventId = (string) Str::uuid();
        foreach ($subs as $sub) {
            $delivery = WebhookDelivery::withoutGlobalScope('store')->create([
                'store_id' => $storeId, 'webhook_subscription_id' => $sub->id, 'event_id' => $eventId, 'topic' => $topic,
                'payload' => ['id' => $eventId, 'topic' => $topic, 'created_at' => now()->toIso8601String(), 'store_id' => $storeId, 'data' => $data],
                'status' => 'pending',
            ]);
            DeliverWebhook::dispatch($delivery->id)->afterCommit();
        }

        return $subs->count();
    }

    public function orderEvent(Order $order, string $topic): void
    {
        if (! $this->wanted($order->store_id, $topic)) {
            return;
        }
        $order = Order::withoutGlobalScope('store')->with('items', 'refunds', 'customer')->find($order->id) ?? $order;
        $this->dispatch($order->store_id, $topic, (new OrderResource($order))->resolve());
    }

    public function productChanged(int $productId, string $topic, ?array $deleted = null): void
    {
        $product = Product::withoutGlobalScope('store')->with('images', 'options', 'variants', 'collections')->find($productId);
        $storeId = $product?->store_id ?? ($deleted['store_id'] ?? null);
        if (! $storeId || ! $this->wanted($storeId, $topic)) {
            return;
        }
        $this->dispatch($storeId, $topic, $product ? (new ProductDetailResource($product))->resolve() : ['id' => $productId, 'title' => $deleted['title'] ?? null]);
    }

    public function customerCreated(Customer $c): void
    {
        if (! $this->wanted($c->store_id, 'customers/create')) {
            return;
        }
        $this->dispatch($c->store_id, 'customers/create', self::customer($c));
    }

    public static function customer(Customer $c): array
    {
        return [
            'id' => $c->id, 'email' => $c->email, 'first_name' => $c->first_name, 'last_name' => $c->last_name, 'phone' => $c->phone,
            'accepts_marketing' => (bool) $c->accepts_marketing, 'orders_count' => (int) $c->orders_count,
            'total_spent' => \App\Support\Money::format((int) $c->total_spent), 'created_at' => $c->created_at?->toIso8601String(),
        ];
    }

    /** Cheap check before building a payload. */
    private function wanted(int $storeId, string $topic): bool
    {
        return WebhookSubscription::withoutGlobalScope('store')->where('store_id', $storeId)->where('enabled', true)->get(['topics'])
            ->contains(fn ($s) => in_array($topic, $s->topics ?? [], true));
    }

    /**
     * Only public http(s) endpoints: no private / loopback / link-local addresses (SSRF).
     * https is required unless the app runs locally.
     * @return string[] the resolved IPs (delivery pins to the first one)
     */
    public static function guard(string $url): array
    {
        $p = parse_url($url);
        $scheme = strtolower($p['scheme'] ?? '');
        $host = strtolower($p['host'] ?? '');
        if (! in_array($scheme, ['https', 'http'], true) || $host === '' || isset($p['user']) || isset($p['pass'])
            || ($scheme === 'http' && app()->environment('production'))) {
            throw new RuntimeException(__('Webhook 地址必须是 https:// 开头的公网地址'));
        }
        $resolver = self::$resolver ?? fn (string $h) => gethostbynamel($h) ?: [];
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $resolver($host);
        if (! $ips) {
            throw new RuntimeException(__('Webhook 域名无法解析'));
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException(__('Webhook 地址指向内网，已拒绝'));
            }
        }

        return $ips;
    }
}
