<?php

namespace App\Http\Controllers;

use App\Billing\Billing;
use App\Models\Domain;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Caddy on-demand TLS "ask" endpoint: https://caddyserver.com/docs/caddyfile/options#on-demand-tls
 *
 * Caddy calls GET /internal/tls/ask?domain=<host> before issuing a certificate for a host
 * it hasn't seen. 200 = go ahead, anything else = refuse. Without this check anyone could
 * point random domains at the server and make it request certificates (rate-limit abuse).
 *
 * Allowed: verified custom domains whose store's plan includes custom domains, and
 * {handle}.{storefront_domain} for existing stores (if you don't use a wildcard cert).
 */
class TlsAskController extends Controller
{
    public function __invoke(Request $request, Billing $billing): Response
    {
        if (! $this->trusted($request)) {
            return response('forbidden', 403);
        }

        $host = rtrim(strtolower(trim((string) $request->query('domain'))), '.');
        if ($host === '' || strlen($host) > 253) {
            return response('no', 404);
        }

        $root = strtolower((string) config('lumee.storefront_domain'));
        if ($host === $root || $host === 'www.'.$root) {
            return response('ok');   // the platform site itself
        }

        if (str_ends_with($host, '.'.$root)) {
            $handle = substr($host, 0, -strlen('.'.$root));

            return ! str_contains($handle, '.') && Store::where('handle', $handle)->exists()
                ? response('ok') : response('no', 404);
        }

        $domain = Domain::where('host', $host)->where('type', 'custom')->whereNotNull('verified_at')->with('store')->first();
        if (! $domain?->store || ! $billing->limits($domain->store)['custom_domains']) {
            return response('no', 404);
        }

        return response('ok');
    }

    /** Caddy runs on the same machine (loopback), or passes the shared token. */
    private function trusted(Request $request): bool
    {
        $token = (string) config('lumee.tls_ask_token');
        if ($token !== '' && hash_equals($token, (string) $request->query('token'))) {
            return true;
        }

        return in_array($request->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true);
    }
}
