<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\I18n\Localization;
use App\Media\Img;
use App\Support\Color;
use App\Tenancy\CurrentStore;
use App\Themes\ThemeContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * The storefront as an installable app ("Add to home screen"): a web app manifest, icons made from the store's
 * logo and colors, and a small service worker that only shows an offline page when there's no connection
 * (it never caches pages, carts or account data).
 */
class PwaController extends Controller
{
    public const SIZES = [180, 192, 512];

    public function manifest(CurrentStore $current, ThemeContext $theme): JsonResponse
    {
        $store = $current->getOrFail();
        $color = (string) $theme->setting('colors.primary', '#111111');

        return response()->json([
            'name' => $store->name,
            'short_name' => mb_substr($store->name, 0, 12),
            'start_url' => app(Localization::class)->url('/').'?utm_source=homescreen',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => (string) $theme->setting('colors.background', '#FFFFFF'),
            'theme_color' => $color,
            'icons' => array_map(fn (int $s) => ['src' => "/pwa-icon/{$s}.png?v=".$this->version($theme), 'sizes' => "{$s}x{$s}", 'type' => 'image/png', 'purpose' => 'any maskable'],
                [192, 512]),
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600']);
    }

    /** Square icon: the logo centered on the background color, or a simple mark in the primary color. */
    public function icon(int $size, ThemeContext $theme, CurrentStore $current): Response
    {
        abort_unless(in_array($size, self::SIZES, true), 404);
        $key = 'pwa-icon:'.$current->id().':'.$size.':'.$this->version($theme);
        $png = Cache::remember($key, now()->addDays(7), fn () => $this->draw($size, $theme));

        return response($png, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=86400']);
    }

    public function worker(): Response
    {
        $js = <<<'JS'
            // Lumée storefront: show an offline page when there's no connection. Nothing else is cached here.
            const CACHE = 'lumee-offline-v1';
            self.addEventListener('install', (e) => {
                e.waitUntil(caches.open(CACHE).then((c) => c.add(new Request('/offline', { cache: 'reload' }))).then(() => self.skipWaiting()));
            });
            self.addEventListener('activate', (e) => {
                e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
            });
            self.addEventListener('fetch', (e) => {
                if (e.request.mode !== 'navigate') return;
                e.respondWith(fetch(e.request).catch(() => caches.match('/offline')));
            });
            JS;

        return response($js, 200, ['Content-Type' => 'application/javascript', 'Cache-Control' => 'no-cache', 'Service-Worker-Allowed' => '/']);
    }

    public function offline(CurrentStore $current, ThemeContext $theme): Response
    {
        $store = $current->getOrFail();
        $html = view('storefront.offline', ['store' => $store, 'color' => (string) $theme->setting('colors.primary', '#111111')])->render();

        return response($html, 200, ['Cache-Control' => 'no-cache']);
    }

    private function version(ThemeContext $theme): string
    {
        return substr(md5($theme->logoUrl().'|'.$theme->setting('colors.primary').'|'.$theme->setting('colors.background')), 0, 8);
    }

    private function draw(int $size, ThemeContext $theme): string
    {
        $hex = fn (string $c, string $fallback) => preg_match('/^#[0-9a-f]{6}$/i', $c) ? $c : $fallback;
        $bg = $hex((string) $theme->setting('colors.background'), '#FFFFFF');
        $primary = $hex((string) $theme->setting('colors.primary'), '#111111');
        $rgb = fn (string $c) => [hexdec(substr($c, 1, 2)), hexdec(substr($c, 3, 2)), hexdec(substr($c, 5, 2))];

        $logo = null;
        if (($path = Img::path($theme->logoUrl())) && Storage::disk(config('lumee.media_disk'))->exists($path)) {
            $logo = @imagecreatefromstring((string) Storage::disk(config('lumee.media_disk'))->get($path)) ?: null;
        }

        $im = imagecreatetruecolor($size, $size);
        imagefill($im, 0, 0, imagecolorallocate($im, ...$rgb($logo ? $bg : $primary)));
        if ($logo) {
            // Inside the "safe zone" of maskable icons (the middle 60%)
            $box = (int) round($size * 0.6);
            $scale = min($box / imagesx($logo), $box / imagesy($logo));
            $w = max(1, (int) round(imagesx($logo) * $scale));
            $h = max(1, (int) round(imagesy($logo) * $scale));
            imagealphablending($im, true);
            imagecopyresampled($im, $logo, intdiv($size - $w, 2), intdiv($size - $h, 2), 0, 0, $w, $h, imagesx($logo), imagesy($logo));
            imagedestroy($logo);
        } else {
            // No logo: a ring in a lighter shade of the brand color
            $ring = imagecolorallocate($im, ...$rgb(Color::mix($primary, '#FFFFFF', 0.85)));
            imagesetthickness($im, max(2, intdiv($size, 18)));
            imagearc($im, intdiv($size, 2), intdiv($size, 2), (int) ($size * 0.42), (int) ($size * 0.42), 0, 360, $ring);
            imagefilledellipse($im, intdiv($size, 2), intdiv($size, 2), (int) ($size * 0.12), (int) ($size * 0.12), $ring);
        }
        ob_start();
        imagepng($im);
        imagedestroy($im);

        return (string) ob_get_clean();
    }
}
