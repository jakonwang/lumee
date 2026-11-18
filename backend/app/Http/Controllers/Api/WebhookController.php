<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WebhookSubscription;
use App\Webhooks\Webhooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Open API: a token manages its own webhook subscriptions (apps subscribe after install).
 * Topics need the matching read scope; app subscriptions are removed when the app is uninstalled.
 */
class WebhookController extends Controller
{
    public const TOPIC_SCOPE = ['orders' => 'read_orders', 'products' => 'read_products', 'customers' => 'read_customers'];

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->own($request)->get()->map(fn ($w) => $this->present($w))]);
    }

    public function store(Request $request): JsonResponse
    {
        $token = $request->attributes->get('api_token');
        $data = $request->validate([
            'url' => ['required', 'string', 'max:500', 'url'],
            'topics' => ['required', 'array', 'min:1'],
            'topics.*' => [Rule::in(array_keys(WebhookSubscription::TOPICS))],
        ]);
        foreach ($data['topics'] as $topic) {
            $need = self::TOPIC_SCOPE[explode('/', $topic)[0]];
            if (! $token->can($need) && ! $token->can('write_'.substr($need, 5))) {
                throw ValidationException::withMessages(['topics' => "The \"{$topic}\" topic needs the \"{$need}\" scope."]);
            }
        }
        try {
            Webhooks::guard($data['url']);
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['url' => 'The URL must be a public https:// address.']);
        }
        if ($this->own($request)->count() >= 20) {
            throw ValidationException::withMessages(['url' => 'A token can have up to 20 webhooks.']);
        }

        $hook = new WebhookSubscription(['url' => $data['url'], 'topics' => array_values(array_unique($data['topics'])), 'enabled' => true]);
        $hook->forceFill(['secret' => Str::random(48), 'app_installation_id' => $token->app_installation_id])->save();

        return response()->json(['data' => $this->present($hook) + ['secret' => $hook->secret]], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->own($request)->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    /** App tokens see their installation's webhooks; private tokens see the store's own (non-app) ones. */
    private function own(Request $request)
    {
        $token = $request->attributes->get('api_token');

        return WebhookSubscription::query()->when($token->app_installation_id,
            fn ($q) => $q->where('app_installation_id', $token->app_installation_id),
            fn ($q) => $q->whereNull('app_installation_id'));
    }

    private function present(WebhookSubscription $w): array
    {
        return ['id' => $w->id, 'url' => $w->url, 'topics' => $w->topics, 'enabled' => $w->enabled, 'created_at' => $w->created_at?->toIso8601String()];
    }
}
