<?php

namespace App\Apps;

use App\Models\ApiToken;
use App\Models\App;
use App\Models\AppInstallation;
use App\Models\OAuthCode;
use App\Models\Store;
use App\Models\User;
use App\Models\WebhookSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * OAuth 2.0 authorization-code flow for third-party apps (RFC 6749, PKCE per RFC 7636).
 *
 *  1. App → merchant admin /oauth/authorize?client_id&redirect_uri&scope&state[&code_challenge&code_challenge_method=S256]
 *  2. Merchant approves → redirect_uri?code=…&state=…&store_id=…   (code: single use, 10 minutes)
 *  3. App server → POST /api/oauth/token {grant_type=authorization_code, code, client_id, client_secret, redirect_uri[, code_verifier]}
 *     ← {access_token: "lm_…", token_type: "bearer", scope, store}
 *
 * The access token is a normal store API token (same /api/v1, scopes and rate limits),
 * linked to the installation so uninstalling revokes it.
 */
class OAuth
{
    public const CODE_TTL_MINUTES = 10;

    /**
     * Validate an authorization request and describe it for the consent screen.
     * @return array{app: App, scopes: string[], redirect_uri: string}
     */
    public function check(array $q, User $user): array
    {
        $fail = fn (string $msg) => throw ValidationException::withMessages(['oauth' => $msg]);
        $app = App::where('client_id', (string) ($q['client_id'] ?? ''))->first() ?? $fail(__('应用不存在或已被删除'));

        // Unlisted apps can only be installed by their own developer (for testing)
        if ($app->status !== 'listed' && $app->user_id !== $user->id) {
            $fail(__('这个应用还没有通过平台审核，暂时不能安装'));
        }
        $redirect = (string) ($q['redirect_uri'] ?? ($app->redirect_uris[0] ?? ''));
        if (! in_array($redirect, $app->redirect_uris ?? [], true)) {
            $fail(__('回调地址和应用登记的不一致'));
        }
        $requested = preg_split('/[\s,]+/', trim((string) ($q['scope'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: ($app->scopes ?? []);
        $unknown = array_diff($requested, $app->scopes ?? []);
        if ($unknown) {
            $fail(__('应用申请了未登记的权限：:p0', ['p0' => implode(', ', $unknown)]));
        }
        if (filled($q['code_challenge'] ?? null) && ($q['code_challenge_method'] ?? 'S256') !== 'S256') {
            $fail(__('只支持 S256 的 PKCE'));
        }

        return ['app' => $app, 'scopes' => array_values(array_intersect(array_keys(ApiToken::SCOPES), $requested)), 'redirect_uri' => $redirect];
    }

    /** The merchant approved: issue a one-time code and return where to send the browser. */
    public function approve(array $q, User $user, Store $store): string
    {
        ['app' => $app, 'scopes' => $scopes, 'redirect_uri' => $redirect] = $this->check($q, $user);
        $code = Str::random(48);
        OAuthCode::create([
            'app_id' => $app->id, 'store_id' => $store->id, 'user_id' => $user->id, 'code_hash' => hash('sha256', $code),
            'scopes' => $scopes, 'redirect_uri' => $redirect, 'code_challenge' => $q['code_challenge'] ?? null,
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
        ]);

        $params = array_filter(['code' => $code, 'state' => $q['state'] ?? null, 'store_id' => (string) $store->id], fn ($v) => $v !== null && $v !== '');

        return $redirect.(str_contains($redirect, '?') ? '&' : '?').http_build_query($params);
    }

    /**
     * Exchange a code for a store access token (installs the app on the store).
     * @return array{token: string, installation: AppInstallation}
     * @throws OAuthError
     */
    public function exchange(array $in): array
    {
        $app = App::where('client_id', (string) ($in['client_id'] ?? ''))->first();
        if (! $app || ! $app->secretMatches((string) ($in['client_secret'] ?? ''))) {
            throw new OAuthError('invalid_client', 'Client authentication failed.', 401);
        }
        if (($in['grant_type'] ?? null) !== 'authorization_code') {
            throw new OAuthError('unsupported_grant_type', 'Only authorization_code is supported.');
        }

        // A code presented twice may have leaked: revoke what it produced (RFC 6749 §4.1.2)
        $reused = OAuthCode::where('code_hash', hash('sha256', (string) ($in['code'] ?? '')))->whereNotNull('used_at')->where('app_id', $app->id)->first();
        if ($reused) {
            $this->revokeTokens(AppInstallation::withoutGlobalScope('store')->where('store_id', $reused->store_id)->where('app_id', $app->id)->first());
            throw new OAuthError('invalid_grant', 'The authorization code is invalid, expired or already used.');
        }

        return DB::transaction(function () use ($app, $in) {
            $code = OAuthCode::where('code_hash', hash('sha256', (string) ($in['code'] ?? '')))->lockForUpdate()->first();
            $bad = ! $code || $code->app_id !== $app->id || $code->used_at || $code->expires_at->isPast()
                || ($code->redirect_uri !== (string) ($in['redirect_uri'] ?? $code->redirect_uri));
            if (! $bad && $code->code_challenge) {
                $verifier = (string) ($in['code_verifier'] ?? '');
                $bad = ! hash_equals($code->code_challenge, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='));
            }
            if ($bad) {
                throw new OAuthError('invalid_grant', 'The authorization code is invalid, expired or already used.');
            }
            $code->forceFill(['used_at' => now()])->save();

            $store = Store::findOrFail($code->store_id);
            $install = AppInstallation::withoutGlobalScope('store')->firstOrNew(['store_id' => $store->id, 'app_id' => $app->id]);
            $install->forceFill(['scopes' => $code->scopes, 'installed_by' => $code->user_id, 'installed_at' => now(), 'uninstalled_at' => null])->save();
            $this->revokeTokens($install);   // one live token per installation

            [$token, $plain] = ApiToken::issue($store, $app->name, $code->scopes, $code->user_id);
            $token->forceFill(['app_installation_id' => $install->id])->save();

            return ['token' => $plain, 'installation' => $install];
        });
    }

    /** Remove an app from a store: token revoked, its webhooks deleted, the app notified. */
    public function uninstall(AppInstallation $install): void
    {
        if (! $install->active()) {
            return;
        }
        $install->forceFill(['uninstalled_at' => now()])->save();
        $this->revokeTokens($install);
        app(AppBilling::class)->cancelForInstallation($install);
        WebhookSubscription::withoutGlobalScope('store')->where('app_installation_id', $install->id)->delete();
        NotifyAppUninstalled::dispatch($install->id)->afterCommit();
    }

    private function revokeTokens(?AppInstallation $install): void
    {
        if ($install) {
            ApiToken::withoutGlobalScope('store')->where('app_installation_id', $install->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }
    }
}
