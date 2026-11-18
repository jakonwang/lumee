<?php

namespace App\Services;

use App\Models\ProductImage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Downloads product images from URLs in an import file — safely.
 * Fetching arbitrary URLs from a server is an SSRF risk, so:
 *  - only http/https on standard ports,
 *  - the host must resolve to PUBLIC addresses only (no 127.x, 10.x, 169.254.x cloud metadata…),
 *  - the connection is pinned to the checked IP (no DNS-rebinding swap between check and fetch),
 *  - no redirects, 10 s timeout, ≤ 10 MB, and the bytes must be a real image.
 */
class ImageFetcher
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** @var callable(string): string[] host → IPs (swappable in tests) */
    private $resolver;

    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? fn (string $host) => gethostbynamel($host) ?: [];
    }

    /** @throws RuntimeException with a merchant-readable message */
    public function fetch(string $url): ProductImage
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || ! in_array($port, [80, 443], true)
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException(__('图片链接格式不支持'));
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolver)($host);
        if (! $ips) {
            throw new RuntimeException(__('图片域名无法解析'));
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException(__('图片地址指向内网，已拒绝'));
            }
        }

        $response = Http::timeout(10)
            ->withOptions([
                'allow_redirects' => false,
                'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ips[0]}"]],   // pin to the checked IP
                'stream' => false,
            ])
            ->withHeaders(['User-Agent' => 'LumeeImageImporter/1.0'])
            ->get($url);

        if ($response->redirect()) {
            throw new RuntimeException(__('图片链接发生跳转，请使用图片的直接地址'));
        }
        if (! $response->successful()) {
            throw new RuntimeException(__('图片下载失败（HTTP ').$response->status().__('）'));
        }
        $bytes = $response->body();
        if (strlen($bytes) === 0 || strlen($bytes) > self::MAX_BYTES) {
            throw new RuntimeException(__('图片为空或超过 10MB'));
        }

        $info = @getimagesizefromstring($bytes);
        $ext = match ($info['mime'] ?? null) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
            default => throw new RuntimeException(__('链接内容不是 JPG/PNG/WebP/GIF 图片')),
        };

        $disk = config('lumee.media_disk');
        $store = app(\App\Tenancy\CurrentStore::class)->getOrFail();
        $path = "stores/{$store->id}/products/".Str::uuid().".{$ext}";
        Storage::disk($disk)->put($path, $bytes, ['visibility' => 'public']);

        return ProductImage::create([
            'disk' => $disk, 'path' => $path, 'width' => $info[0], 'height' => $info[1], 'size' => strlen($bytes),
        ]);
    }
}
