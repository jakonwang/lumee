<?php

namespace App\LeadFinder;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fetches a public web page (a prospect's website) without opening an SSRF hole:
 * http/https on standard ports only, every hop must resolve to public IPs, the connection is
 * pinned to the checked IP, redirects are followed by hand (≤ 4, each re-checked), ≤ 1.5 MB, HTML only.
 */
class SafeFetcher
{
    public const MAX_BYTES = 1_500_000;

    public const USER_AGENT = 'Mozilla/5.0 (compatible; LumeeLeadFinder/1.0; business contact lookup)';

    /** @var callable(string): string[] */
    private $resolver;

    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? fn (string $host) => gethostbynamel($host) ?: [];
    }

    /** @return array{url: string, html: string} @throws RuntimeException with a merchant-readable reason */
    public function get(string $url, int $timeout = 8): array
    {
        for ($hop = 0; $hop <= 4; $hop++) {
            [$host, $port] = $this->check($url);
            $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolver)($host);
            if (! $ips) {
                throw new RuntimeException('域名无法解析');
            }
            foreach ($ips as $ip) {
                if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    throw new RuntimeException('地址指向内网，已跳过');
                }
            }

            $response = $this->request($url, $host, $port, $ips[0], $timeout);

            if ($response->redirect()) {
                $next = $response->header('Location');
                if (! $next) {
                    throw new RuntimeException('跳转地址为空');
                }
                $url = self::absolute($next, $url);

                continue;
            }
            if (! $response->successful()) {
                throw new RuntimeException(in_array($response->status(), [401, 403, 429, 503], true)
                    ? '网站开启了访问防护（HTTP '.$response->status().'）' : '网站返回 HTTP '.$response->status());
            }
            $type = strtolower($response->header('Content-Type'));
            if ($type !== '' && ! str_contains($type, 'html')) {
                throw new RuntimeException('不是网页');
            }
            $body = $response->body();

            return ['url' => $url, 'html' => strlen($body) > self::MAX_BYTES ? substr($body, 0, self::MAX_BYTES) : $body];
        }
        throw new RuntimeException('跳转次数过多');
    }

    private function request(string $url, string $host, int $port, string $ip, int $timeout): Response
    {
        try {
            return Http::timeout($timeout)->connectTimeout(5)
                ->withOptions([
                    'allow_redirects' => false,
                    'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"], CURLOPT_MAXFILESIZE => self::MAX_BYTES],   // pin to the checked IP
                ])
                ->withHeaders(['User-Agent' => self::USER_AGENT, 'Accept' => 'text/html,application/xhtml+xml', 'Accept-Language' => 'en,*;q=0.5'])
                ->get($url);
        } catch (ConnectionException $e) {
            $m = $e->getMessage();
            throw new RuntimeException(match (true) {
                str_contains($m, 'error 28') => '网站连接超时',
                str_contains($m, 'error 6:') => '域名无法解析',
                str_contains($m, 'error 7:') => '网站拒绝连接',
                str_contains($m, 'error 60'), str_contains($m, 'error 35'), str_contains($m, 'SSL') => '网站证书有问题',
                default => '网站无法访问',
            });
        }
    }

    /** @return array{0: string, 1: int} */
    private function check(string $url): array
    {
        $p = parse_url($url);
        $scheme = strtolower($p['scheme'] ?? '');
        $host = strtolower($p['host'] ?? '');
        $port = $p['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || ! in_array($port, [80, 443], true) || isset($p['user']) || isset($p['pass'])) {
            throw new RuntimeException('网址格式不支持');
        }

        return [$host, (int) $port];
    }

    /** Resolve a (possibly relative) link against the page it was found on. */
    public static function absolute(string $href, string $base): string
    {
        $href = trim($href);
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $b = parse_url($base);
        $origin = ($b['scheme'] ?? 'https').'://'.($b['host'] ?? '');
        if (str_starts_with($href, '//')) {
            return ($b['scheme'] ?? 'https').':'.$href;
        }
        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');

        return $origin.($dir ?: '/').$href;
    }

    /** "https://www.Example.com/x" → "example.com" */
    public static function domain(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host ? preg_replace('/^www\./', '', $host) : null;
    }
}
