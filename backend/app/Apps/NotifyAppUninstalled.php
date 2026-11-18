<?php

namespace App\Apps;

use App\Models\AppInstallation;
use App\Webhooks\Webhooks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Http;
use Throwable;

/** POST {"topic":"app/uninstalled","store_id","app_id","uninstalled_at"} to the app's uninstall URL, signed with its signing secret. */
class NotifyAppUninstalled implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public array $backoff = [60, 600];

    public function __construct(public int $installationId) {}

    public function handle(): void
    {
        $install = AppInstallation::withoutGlobalScope('store')->with('app')->find($this->installationId);
        $app = $install?->app;
        if (! $app || ! $app->uninstall_url || ! $app->signing_secret) {
            return;
        }
        $body = json_encode(['topic' => 'app/uninstalled', 'store_id' => $install->store_id, 'app_id' => $app->id,
            'uninstalled_at' => $install->uninstalled_at?->toIso8601String()], JSON_UNESCAPED_SLASHES);
        try {
            $ips = Webhooks::guard($app->uninstall_url);
            $p = parse_url($app->uninstall_url);
            $port = $p['port'] ?? (strtolower($p['scheme']) === 'https' ? 443 : 80);
            Http::timeout(10)->withOptions(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => ["{$p['host']}:{$port}:{$ips[0]}"]]])
                ->withHeaders([
                    'X-Lumee-Topic' => 'app/uninstalled',
                    'X-Lumee-Store-Id' => (string) $install->store_id,
                    'X-Lumee-Hmac-Sha256' => base64_encode(hash_hmac('sha256', $body, $app->signing_secret, true)),
                ])->withBody($body, 'application/json')->post($app->uninstall_url);
        } catch (Throwable $e) {
            report($e);   // best effort: the token is already revoked either way
        }
    }
}
