<?php

namespace App\Themes;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Store;
use App\Models\StoreMedia;
use App\Support\Color;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Home banners made from the store's own product photos (GD, no outside service): a soft background in
 * the brand colours with up to three best sellers as cards, one wide image for computers and one tall
 * image for phones. The slideshow writes its heading over it, so the image itself has no text.
 */
class BannerComposer
{
    /** @return array{desktop: int, mobile: int}|null StoreMedia ids, or null when there aren't enough photos */
    public function compose(Store $store, Collection $products, string $primary, string $background): ?array
    {
        $images = $this->render($products, $primary, $background);

        return $images ? ['desktop' => $this->store($store, $images['desktop']), 'mobile' => $this->store($store, $images['mobile'])] : null;
    }

    /** The two banners as WebP bytes (not saved). @return array{desktop: string, mobile: string}|null */
    public function render(Collection $products, string $primary, string $background): ?array
    {
        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }
        $photos = [];
        foreach ($products as $p) {
            /** @var Product $p */
            $img = ProductImage::withoutGlobalScope('store')->where('product_id', $p->id)->orderBy('position')->first();
            $bytes = $img ? rescue(fn () => Storage::disk($img->disk)->get($img->path), null, false) : null;
            $gd = $bytes ? @imagecreatefromstring($bytes) : false;
            if ($gd) {
                $photos[] = $gd;
            }
            if (count($photos) === 3) {
                break;
            }
        }
        if (! $photos) {
            return null;
        }
        $bg = Color::isHex($background) ? $background : '#FFFFFF';
        $primary = Color::isHex($primary) ? $primary : '#333333';
        $out = [
            'desktop' => $this->webp($this->canvas(1600, 640, $photos, $primary, $bg, false)),
            'mobile' => $this->webp($this->canvas(900, 1200, $photos, $primary, $bg, true)),
        ];
        foreach ($photos as $gd) {
            imagedestroy($gd);
        }

        return $out;
    }

    private function canvas(int $w, int $h, array $photos, string $primary, string $bg, bool $tall): \GdImage
    {
        $im = imagecreatetruecolor($w, $h);
        // Vertical gradient: a light tint of the brand colour into the page background
        [$r1, $g1, $b1] = Color::rgb(Color::mix($primary, '#FFFFFF', 0.82));
        [$r2, $g2, $b2] = Color::rgb(Color::mix($bg, $primary, 0.12));
        for ($y = 0; $y < $h; $y++) {
            $t = $y / max(1, $h - 1);
            imageline($im, 0, $y, $w, $y, imagecolorallocate($im, (int) ($r1 + ($r2 - $r1) * $t), (int) ($g1 + ($g2 - $g1) * $t), (int) ($b1 + ($b2 - $b1) * $t)));
        }
        // A large soft circle in the brand colour for depth
        [$pr, $pg, $pb] = Color::rgb($primary);
        imagefilledellipse($im, (int) ($w * 0.78), (int) ($h * 0.55), (int) ($h * 1.1), (int) ($h * 1.1), imagecolorallocatealpha($im, $pr, $pg, $pb, 112));

        // Product cards: in a row (wide) or a staggered column (tall)
        $n = count($photos);
        $card = $tall ? (int) ($w * 0.46) : (int) ($h * 0.62);
        $gap = (int) ($card * 0.12);
        for ($i = 0; $i < $n; $i++) {
            if ($tall) {
                $x = $i % 2 === 0 ? (int) ($w * 0.08) : (int) ($w - $card - $w * 0.08);
                $y = (int) ($h * 0.18 + $i * ($card * 0.78));
            } else {
                $total = $n * $card + ($n - 1) * $gap;
                $x = (int) (($w - $total) / 2 + $i * ($card + $gap));
                $y = (int) (($h - $card) / 2 + ($i % 2 ? $h * 0.06 : -$h * 0.04));
            }
            $this->card($im, $photos[$i], $x, $y, $card);
        }

        return $im;
    }

    /** A white rounded-corner card with the photo fitted inside and a soft shadow. */
    private function card(\GdImage $im, \GdImage $photo, int $x, int $y, int $size): void
    {
        imagealphablending($im, true);
        $shadow = imagecolorallocatealpha($im, 0, 0, 0, 115);
        imagefilledrectangle($im, $x + 8, $y + 12, $x + $size + 8, $y + $size + 12, $shadow);
        imagefilledrectangle($im, $x, $y, $x + $size, $y + $size, imagecolorallocate($im, 255, 255, 255));
        $pad = (int) ($size * 0.06);
        $sw = imagesx($photo);
        $sh = imagesy($photo);
        $scale = min(($size - 2 * $pad) / $sw, ($size - 2 * $pad) / $sh);
        $dw = (int) ($sw * $scale);
        $dh = (int) ($sh * $scale);
        imagecopyresampled($im, $photo, $x + (int) (($size - $dw) / 2), $y + (int) (($size - $dh) / 2), 0, 0, $dw, $dh, $sw, $sh);
    }

    private function webp(\GdImage $im): string
    {
        ob_start();
        imagewebp($im, null, 82);
        imagedestroy($im);

        return (string) ob_get_clean();
    }

    /** Save image bytes (WebP / PNG / JPEG) to the store's media library. */
    public function store(Store $store, string $bytes): int
    {
        $info = @getimagesizefromstring($bytes);
        [$w, $h] = [$info[0] ?? null, $info[1] ?? null];
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg'][$info['mime'] ?? ''] ?? 'webp';
        $disk = config('lumee.media_disk');
        $path = "stores/{$store->id}/media/auto-banner-".Str::lower(Str::random(10)).'.'.$ext;
        Storage::disk($disk)->put($path, $bytes, ['visibility' => 'public']);
        $m = new StoreMedia(['disk' => $disk, 'path' => $path, 'width' => $w, 'height' => $h, 'size' => strlen($bytes)]);
        $m->forceFill(['store_id' => $store->id])->save();

        return $m->id;
    }
}
