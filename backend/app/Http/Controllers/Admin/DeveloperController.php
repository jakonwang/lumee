<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Support\Tr;
use App\Tenancy\CurrentStore;
use App\Webhooks\DeliverWebhook;
use App\Webhooks\Webhooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Settings → Developers: API tokens and webhook subscriptions. */
class DeveloperController extends Controller
{
    public const MAX_TOKENS = 20;

    public const MAX_WEBHOOKS = 20;

    public function index(): JsonResponse
    {
        return response()->json([
            'tokens' => ApiToken::whereNull('revoked_at')->whereNull('app_installation_id')->latest('id')->get()->map(fn (ApiToken $t) => $this->token($t)),
            'webhooks' => WebhookSubscription::whereNull('app_installation_id')->latest('id')->get()->map(fn (WebhookSubscription $w) => $this->webhook($w)),
            'scopes' => collect(Tr::deep(ApiToken::SCOPES))->map(fn ($v, $k) => ['key' => $k, 'label' => $v[0], 'description' => $v[1]])->values(),
            'topics' => collect(Tr::map(WebhookSubscription::TOPICS))->map(fn ($label, $k) => ['key' => $k, 'label' => $label])->values(),
            'api_base' => rtrim((string) config('app.url'), '/').'/api/v1',
        ]);
    }

    public function createToken(Request $request, CurrentStore $current): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => [Rule::in(array_keys(ApiToken::SCOPES))],
        ], ['name.required' => __('请填写令牌名称，例如 ERP 同步'), 'scopes.required' => __('请至少选择一项权限')]);
        if (ApiToken::whereNull('revoked_at')->count() >= self::MAX_TOKENS) {
            throw ValidationException::withMessages(['name' => __('最多可以创建 :p0 个令牌', ['p0' => self::MAX_TOKENS])]);
        }
        [$token, $plain] = ApiToken::issue($current->getOrFail(), $data['name'], $data['scopes'], $request->user()->id);

        // The full token is only ever shown here
        return response()->json(['data' => $this->token($token), 'token' => $plain], 201);
    }

    public function revokeToken(int $id): JsonResponse
    {
        ApiToken::whereNull('revoked_at')->whereNull('app_installation_id')->findOrFail($id)->forceFill(['revoked_at' => now()])->save();

        return response()->json(['ok' => true]);
    }

    public function createWebhook(Request $request): JsonResponse
    {
        $data = $this->validatedWebhook($request);
        if (WebhookSubscription::count() >= self::MAX_WEBHOOKS) {
            throw ValidationException::withMessages(['url' => __('最多可以添加 :p0 个 Webhook', ['p0' => self::MAX_WEBHOOKS])]);
        }
        $hook = new WebhookSubscription(['url' => $data['url'], 'topics' => $data['topics'], 'enabled' => true]);
        $hook->forceFill(['secret' => Str::random(48)])->save();

        return response()->json(['data' => $this->webhook($hook), 'secret' => $hook->secret], 201);
    }

    public function updateWebhook(Request $request, int $id): JsonResponse
    {
        $hook = WebhookSubscription::findOrFail($id);
        $data = $this->validatedWebhook($request) + $request->validate(['enabled' => ['required', 'boolean']]);
        $hook->fill(['url' => $data['url'], 'topics' => $data['topics'], 'enabled' => $data['enabled']]);
        if ($data['enabled']) {
            $hook->forceFill(['failure_count' => 0, 'disabled_reason' => null]);   // turning it back on gives it a fresh start
        }
        $hook->save();

        return response()->json(['data' => $this->webhook($hook)]);
    }

    public function deleteWebhook(int $id): JsonResponse
    {
        WebhookSubscription::findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    public function secret(int $id): JsonResponse
    {
        return response()->json(['secret' => WebhookSubscription::findOrFail($id)->secret]);
    }

    /** Send a "webhooks/test" event right now and report what the endpoint answered. */
    public function test(int $id, CurrentStore $current): JsonResponse
    {
        $hook = WebhookSubscription::findOrFail($id);
        $eventId = (string) Str::uuid();
        $delivery = WebhookDelivery::create([
            'store_id' => $current->id(), 'webhook_subscription_id' => $hook->id, 'event_id' => $eventId, 'topic' => 'webhooks/test',
            'payload' => ['id' => $eventId, 'topic' => 'webhooks/test', 'created_at' => now()->toIso8601String(), 'store_id' => $current->id(),
                'data' => ['message' => 'This is a test event from Lumée.']],
            'status' => 'pending',
        ]);
        (new DeliverWebhook($delivery->id, once: true))->handle();

        return response()->json(['data' => $this->delivery($delivery->fresh())]);
    }

    public function deliveries(int $id): JsonResponse
    {
        $hook = WebhookSubscription::findOrFail($id);

        return response()->json(['data' => $hook->deliveries()->latest('id')->limit(50)->get()->map(fn ($d) => $this->delivery($d))]);
    }

    /** Send a past event again (same event id, so receivers can de-duplicate). */
    public function redeliver(int $deliveryId): JsonResponse
    {
        $old = WebhookDelivery::findOrFail($deliveryId);
        $new = WebhookDelivery::create([
            ...$old->only('store_id', 'webhook_subscription_id', 'event_id', 'topic', 'payload'),
            'status' => 'pending',
        ]);
        (new DeliverWebhook($new->id, once: true))->handle();

        return response()->json(['data' => $this->delivery($new->fresh())]);
    }

    private function validatedWebhook(Request $request): array
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:500', 'url'],
            'topics' => ['required', 'array', 'min:1'],
            'topics.*' => [Rule::in(array_keys(WebhookSubscription::TOPICS))],
        ], ['url.url' => __('Webhook 地址格式不正确'), 'topics.required' => __('请至少选择一个事件')]);
        try {
            Webhooks::guard($data['url']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }
        $data['topics'] = array_values(array_unique($data['topics']));

        return $data;
    }

    private function token(ApiToken $t): array
    {
        return ['id' => $t->id, 'name' => $t->name, 'prefix' => $t->token_prefix, 'scopes' => $t->scopes, 'last_used_at' => $t->last_used_at, 'created_at' => $t->created_at];
    }

    private function webhook(WebhookSubscription $w): array
    {
        return [
            'id' => $w->id, 'url' => $w->url, 'topics' => $w->topics, 'enabled' => $w->enabled, 'failure_count' => (int) $w->failure_count,
            'disabled_reason' => $w->disabled_reason, 'last_success_at' => $w->last_success_at, 'last_failure_at' => $w->last_failure_at,
        ];
    }

    private function delivery(WebhookDelivery $d): array
    {
        return [
            'id' => $d->id, 'event_id' => $d->event_id, 'topic' => $d->topic, 'status' => $d->status, 'attempts' => (int) $d->attempts,
            'response_code' => $d->response_code, 'response_body' => $d->response_body, 'created_at' => $d->created_at, 'delivered_at' => $d->delivered_at,
        ];
    }
}
