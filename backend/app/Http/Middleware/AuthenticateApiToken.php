<?php

namespace App\Http\Middleware;

use App\Billing\Billing;
use App\Models\ApiToken;
use App\Models\Store;
use App\Tenancy\CurrentStore;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Open API v1: "Authorization: Bearer lm_…" (a store API token). Sets the current store, so
 * every query is tenant-scoped exactly like the admin. Responses are in English.
 */
class AuthenticateApiToken
{
    public function __construct(private CurrentStore $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale('en');
        $plain = (string) $request->bearerToken();
        $token = str_starts_with($plain, 'lm_') ? ApiToken::findActive($plain) : null;
        if (! $token) {
            return response()->json(['error' => 'unauthorized', 'message' => 'Missing or invalid API token.'], 401);
        }

        $store = Store::find($token->store_id);
        if (! $store || ! in_array(app(Billing::class)->state($store), Billing::OPEN, true)) {
            return response()->json(['error' => 'store_unavailable', 'message' => 'This store is paused. The owner needs to choose a plan to use the API.'], 402);
        }

        $this->current->set($store);
        // Full access within the token's scopes (no staff permission checks apply)
        $this->current->setMembership('owner', []);
        $request->attributes->set('api_token', $token);

        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $response = $next($request);
        // A change from the admin / an app may show on the storefront (also bulk writes that skip model events)
        if (! $request->isMethodSafe() && $response->isSuccessful()) {
            \App\Storefront\PageCache::bump($store->id);
        }

        return $response;
    }
}
