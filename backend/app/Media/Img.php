<?php

namespace App\Media;

use App\Models\Domain;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

/**
 * Responsive storefront images. Uploaded images (on the media disk) are served in a few widths as WebP through
 * /_img/{width}/{path} — made on first request, then kept on the disk next to the original (see ImageVariantController).
 * Images from elsewhere (external URLs, theme assets) are left as they are.
 *
 *   <img {{ \App\Media\Img::attrs($url, 'card') }} alt="…" loading="lazy">
 */
class Img
{
    /** Widths a variant can be made in (anything else is a 404, so nobody can fill the disk with sizes). */
    public const WIDTHS = [160, 320, 480, 640, 960, 1280, 1920];

    /** preset => [widths in srcset, sizes, width used for src] */
    public const PRESETS = [
        'hero' => [[640, 960, 1280, 1920], '100vw', 1280],
        'half' => [[480, 640, 960, 1280], '(max-width: 768px) 100vw, 50vw', 960],
        'product' => [[480, 640, 960, 1280], '(max-width: 900px) 100vw, 50vw', 960],
        'card' => [[320, 480, 640], '(max-width: 640px) 50vw, (max-width: 1024px) 33vw, 25vw', 480],
        'thumb' => [[160, 320], '96px', 160],
    ];

    public const VARIANT_DIR = '_variants';

    /** The file's path on the media disk when the URL points at an upload we can resize, else null. */
    public static function path(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        $base = Storage::disk(config('lumee.media_disk'))->url('');
        // Local disks: the URL's host can be APP_URL or the shop's own host — compare paths only
        $prefix = str_starts_with($base, 'http') && config('filesystems.disks.'.config('lumee.media_disk').'.driver') === 'local'
            ? (string) parse_url($base, PHP_URL_PATH)
            : $base;
        if (str_starts_with($prefix, '/') && ($host = parse_url($url, PHP_URL_HOST)) && ! self::ownHost((string) $host, $base)) {
            return null;   // another site's image that happens to have a similar path
        }
        $candidate = str_starts_with($prefix, '/') ? (string) parse_url($url, PHP_URL_PATH) : $url;
        if ($prefix === '' || ! str_starts_with($candidate, $prefix)) {
            return null;
        }
        $path = rawurldecode(substr($candidate, strlen($prefix)));

        return self::resizable($path) ? $path : null;
    }

    /** A host this app serves uploads from: APP_URL's / the disk URL's host, this request's host, or a store host. */
    private static function ownHost(string $host, string $base): bool
    {
        $host = strtolower($host);
        $mine = array_filter([parse_url($base, PHP_URL_HOST), parse_url((string) config('app.url'), PHP_URL_HOST), app()->runningInConsole() ? null : request()->getHost()]);
        if (in_array($host, array_map('strtolower', $mine), true)) {
            return true;
        }
        $root = strtolower((string) config('lumee.storefront_domain'));

        return ($root !== '' && str_ends_with($host, '.'.$root)) || Domain::withoutGlobalScopes()->where('host', $host)->exists();
    }

    public static function resizable(string $path): bool
    {
        return str_starts_with($path, 'stores/') && ! str_contains($path, '..')
            && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true);
    }

    /** URL of the image at (at most) this width; the original URL when it can't be resized. */
    public static function url(?string $url, int $width): ?string
    {
        $path = self::path($url);

        return $path ? '/_img/'.$width.'/'.implode('/', array_map('rawurlencode', explode('/', $path))) : $url;
    }

    /** src + srcset + sizes for an <img>. */
    public static function attrs(?string $url, string $preset = 'card'): HtmlString
    {
        [$widths, $sizes, $default] = self::PRESETS[$preset] ?? self::PRESETS['card'];
        if (! self::path($url)) {
            return new HtmlString('src="'.e((string) $url).'"');
        }
        $set = implode(', ', array_map(fn (int $w) => self::url($url, $w).' '.$w.'w', $widths));

        return new HtmlString('src="'.e(self::url($url, $default)).'" srcset="'.e($set).'" sizes="'.e($sizes).'"');
    }

    public static function variantPath(int $width, string $path): string
    {
        return self::VARIANT_DIR.'/'.$width.'/'.$path.'.webp';
    }

    /** Remove the made sizes of an original (when it's deleted or replaced). */
    public static function forget(string $disk, ?string $path): void
    {
        if ($path && self::resizable($path)) {
            Storage::disk($disk)->delete(array_map(fn (int $w) => self::variantPath($w, $path), self::WIDTHS));
        }
    }

    /** Scale down to the width (never up) and encode as WebP. Null when the image can't be read. */
    public static function resize(string $bytes, int $width): ?string
    {
        @ini_set('memory_limit', '512M');   // large uploads (up to 8000px) need room while decoding
        $src = @imagecreatefromstring($bytes);
        if (! $src) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        if ($w > $width) {
            $dst = imagecreatetruecolor($width, max(1, (int) round($h * $width / $w)));
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
            imagedestroy($src);
            $src = $dst;
        } else {
            imagepalettetotruecolor($src);
            imagealphablending($src, false);
            imagesavealpha($src, true);
        }
        ob_start();
        $ok = function_exists('imagewebp') ? imagewebp($src, null, 80) : imagejpeg($src, null, 82);
        $out = (string) ob_get_clean();
        imagedestroy($src);

        return $ok && $out !== '' ? $out : null;
    }
}
