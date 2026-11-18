<?php

namespace App\Http\Controllers\Api;

use App\Apps\OAuth;
use App\Apps\OAuthError;
use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /api/oauth/token — the app's server exchanges an authorization code for a store token. */
class OAuthController extends Controller
{
    public function token(Request $request, OAuth $oauth): JsonResponse
    {
        try {
            ['token' => $token, 'installation' => $install] = $oauth->exchange($request->only('grant_type', 'code', 'client_id', 'client_secret', 'redirect_uri', 'code_verifier'));
        } catch (OAuthError $e) {
            return response()->json(['error' => $e->error, 'error_description' => $e->getMessage()], $e->status)
                ->header('Cache-Control', 'no-store');
        }
        $store = Store::find($install->store_id);

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'scope' => implode(' ', $install->scopes),
            'store' => ['id' => $store->id, 'name' => $store->name, 'url' => $store->storefrontUrl(), 'currency' => $store->currency],
        ])->header('Cache-Control', 'no-store');
    }
}
