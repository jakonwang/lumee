<?php

namespace App\Content;

use App\Models\ProductImage;
use App\Models\Store;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Makes product images look like one brand (GD, no external service needed):
 * trims plain borders, then places the product on a canvas with the brand ratio, background
 * colour and margin, 1600px tall, saved as WebP. Optionally removes the background first with
 * remove.bg (the store's own key). The original file is kept, so it can always be reverted.
 * Processing always starts from the original, so repeated runs never degrade the image.
 */
class ImageStudio
{
    public const HEIGHT = 1600;

    public function standardize(Store $store, ProductImage $img, bool $removeBackground = false): ProductImage
    {
        $kit = BrandKit::for($store);
        $k = $kit->all();
        $source = $img->original_path ?: $img->path;
        $bytes = Storage::disk($img->disk)->get($source);
        if (! $bytes) {
            throw new RuntimeException('图片文件不存在');
        }
        if ($removeBackground) {
            $bytes = $this->removeBackground($kit, $bytes);
        }
        $src = @imagecreatefromstring($bytes);
        if (! $src) {
            throw new RuntimeException('无法读取这张图片');
        }
        imagepalettetotruecolor($src);
        $src = $this->trim($src);

        // Canvas in the brand ratio, product centred inside the margin
        $h = self::HEIGHT;
        $w = (int) round($h * BrandKit::ratio($k['ratio']));
        [$r, $g, $b] = sscanf($k['background'], '#%02x%02x%02x');
        $canvas = imagecreatetruecolor($w, $h);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, $r, $g, $b));
        imagealphablending($canvas, true);

        $sw = imagesx($src);
        $sh = imagesy($src);
        $box = 1 - 2 * $k['padding'] / 100;
        $scale = min($w * $box / $sw, $h * $box / $sh, 2.0);   // never blow a small image up more than 2×
        $dw = (int) round($sw * $scale);
        $dh = (int) round($sh * $scale);
        imagecopyresampled($canvas, $src, (int) (($w - $dw) / 2), (int) (($h - $dh) / 2), 0, 0, $dw, $dh, $sw, $sh);
        imagedestroy($src);

        ob_start();
        imagewebp($canvas, null, 86);
        $out = (string) ob_get_clean();
        imagedestroy($canvas);

        $path = "stores/{$store->id}/products/".Str::uuid().'.webp';
        Storage::disk($img->disk)->put($path, $out, ['visibility' => 'public']);
        if ($img->original_path && $img->path !== $img->original_path) {
            Storage::disk($img->disk)->delete($img->path);   // the previous processed version
        }
        $img->forceFill([
            'original_path' => $source, 'path' => $path, 'width' => $w, 'height' => $h, 'size' => strlen($out),
            'processed' => ['ratio' => $k['ratio'], 'background' => $k['background'], 'padding' => $k['padding'],
                'removed_background' => $removeBackground, 'at' => now()->toIso8601String()],
        ])->save();

        return $img;
    }

    public function revert(ProductImage $img): ProductImage
    {
        if (! $img->original_path) {
            return $img;
        }
        $bytes = Storage::disk($img->disk)->get($img->original_path);
        $info = $bytes ? @getimagesizefromstring($bytes) : false;
        Storage::disk($img->disk)->delete($img->path);
        $img->forceFill(['path' => $img->original_path, 'original_path' => null, 'processed' => null,
            'width' => $info[0] ?? $img->width, 'height' => $info[1] ?? $img->height, 'size' => $bytes ? strlen($bytes) : $img->size])->save();

        return $img;
    }

    /** Cut away a plain border (the colour of the top-left corner, if it is light), so products sit consistently. */
    private function trim(\GdImage $im): \GdImage
    {
        $c = imagecolorsforindex($im, imagecolorat($im, 0, 0));
        $light = $c['red'] > 200 && $c['green'] > 200 && $c['blue'] > 200;
        if (! $light && $c['alpha'] < 100) {
            return $im;   // photo with a scene background: keep the composition
        }
        $cropped = @imagecropauto($im, IMG_CROP_THRESHOLD, 8, imagecolorat($im, 0, 0));
        if ($cropped instanceof \GdImage && imagesx($cropped) > 20 && imagesy($cropped) > 20) {
            imagedestroy($im);

            return $cropped;
        }

        return $im;
    }

    private function removeBackground(BrandKit $kit, string $bytes): string
    {
        $key = $kit->removeBgKey() ?? throw new RuntimeException('还没有填写 remove.bg API Key（在「内容工坊 › 品牌规范」里填写）');
        $res = Http::timeout(60)->withHeaders(['X-Api-Key' => $key])
            ->attach('image_file', $bytes, 'image')
            ->post('https://api.remove.bg/v1.0/removebg', ['size' => 'auto', 'format' => 'png']);
        if (! $res->successful()) {
            throw new RuntimeException(match ($res->status()) {
                402 => 'remove.bg 额度不足，请充值后再试',
                403 => 'remove.bg API Key 无效',
                429 => 'remove.bg 请求太频繁，请稍后再试',
                default => 'remove.bg 抠图失败（HTTP '.$res->status().'）',
            });
        }

        return $res->body();
    }
}
