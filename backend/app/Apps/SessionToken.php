<?php

namespace App\Apps;

use App\Models\App;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * App Bridge session tokens: a short-lived JWT (HS256, signed with the app's signing secret) that an
 * embedded app receives from the admin and sends to its own backend, which verifies it to know which
 * store and staff member is using it — without third-party cookies inside the iframe.
 *
 * Claims: iss (admin URL), dest (storefront URL), aud (client id), sub (staff user id),
 *         store_id, store (handle), exp (+60 s), nbf, iat, jti.
 */
class SessionToken
{
    public const TTL = 60;

    public static function issue(App $app, Store $store, User $user): string
    {
        $now = now()->timestamp;
        $payload = [
            'iss' => rtrim((string) config('lumee.admin_url'), '/'), 'dest' => $store->storefrontUrl(), 'aud' => $app->client_id,
            'sub' => (string) $user->id, 'store_id' => $store->id, 'store' => $store->handle,
            'exp' => $now + self::TTL, 'nbf' => $now - 5, 'iat' => $now, 'jti' => (string) Str::uuid(),
        ];
        $segments = [self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])), self::b64(json_encode($payload, JSON_UNESCAPED_SLASHES))];
        $segments[] = self::b64(hash_hmac('sha256', implode('.', $segments), (string) $app->signing_secret, true));

        return implode('.', $segments);
    }

    /** Verify a token (used by tests and as the reference for app developers). Returns the claims or null. */
    public static function verify(string $jwt, string $secret, string $clientId): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        $expected = self::b64(hash_hmac('sha256', $parts[0].'.'.$parts[1], $secret, true));
        if (! hash_equals($expected, $parts[2])) {
            return null;
        }
        $claims = json_decode(self::unb64($parts[1]), true);
        $now = now()->timestamp;
        if (! is_array($claims) || ($claims['aud'] ?? null) !== $clientId || ($claims['exp'] ?? 0) < $now || ($claims['nbf'] ?? 0) > $now) {
            return null;
        }

        return $claims;
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/'));
    }
}
