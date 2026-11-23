<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Media\Img;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** /_img/{width}/{path}: an uploaded image at a storefront size, as WebP. Made once, then served from the disk. */
class ImageVariantController extends Controller
{
    public function __invoke(int $width, string $path): Response
    {
        abort_unless(in_array($width, Img::WIDTHS, true) && Img::resizable($path), 404);
        $disk = Storage::disk(config('lumee.media_disk'));
        $variant = Img::variantPath($width, $path);

        if ($disk->exists($variant)) {
            $bytes = $disk->get($variant);
        } else {
            abort_unless($disk->exists($path), 404);
            $bytes = Img::resize((string) $disk->get($path), $width);
            if ($bytes === null) {
                return redirect($disk->url($path));   // unreadable by GD: let the browser have the original
            }
            $disk->put($variant, $bytes, ['visibility' => 'public']);
        }

        return response($bytes, 200, [
            'Content-Type' => function_exists('imagewebp') ? 'image/webp' : 'image/jpeg',
            'Cache-Control' => 'public, max-age=31536000, immutable',   // the path of an upload never changes
        ]);
    }
}
