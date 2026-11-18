<?php

namespace App\Services;

/**
 * Checks that a merchant's custom domain points at the platform:
 * a CNAME to config('lumee.cname_target'), or an A record to config('lumee.server_ip').
 * Bound in the container so tests can swap in a fake.
 */
class DnsChecker
{
    /** @return array{ok:bool, found:string[]} */
    public function check(string $host): array
    {
        // Local development: *.localhost / *.test never have public DNS but resolve to this machine
        if (app()->isLocal() && preg_match('/\.(localhost|test)$/', $host)) {
            return ['ok' => true, 'found' => ['(local development domain)']];
        }

        $target = strtolower(rtrim((string) config('lumee.cname_target'), '.'));
        $ip = config('lumee.server_ip');
        $found = [];

        foreach (@dns_get_record($host, DNS_CNAME) ?: [] as $r) {
            $value = strtolower(rtrim($r['target'] ?? '', '.'));
            $found[] = "CNAME {$value}";
            if ($value === $target) {
                return ['ok' => true, 'found' => $found];
            }
        }
        foreach (@dns_get_record($host, DNS_A) ?: [] as $r) {
            $found[] = 'A '.($r['ip'] ?? '');
            if ($ip && ($r['ip'] ?? null) === $ip) {
                return ['ok' => true, 'found' => $found];
            }
        }

        return ['ok' => false, 'found' => $found];
    }
}
