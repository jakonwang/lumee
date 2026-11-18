<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StoreMedia;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Theme images (logo, banners). Product images use ImageUploadController. */
class MediaUploadController extends Controller
{
    public function __invoke(Request $request, CurrentStore $current): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240', 'dimensions:max_width=8000,max_height=8000'],
        ], [
            'file.mimes' => __('只支持 JPG / PNG / WebP / GIF 图片'),
            'file.max' => __('图片不能超过 10MB'),
        ]);

        $file = $request->file('file');
        $disk = config('lumee.media_disk');
        $path = $file->storeAs('stores/'.$current->id().'/theme', Str::uuid().'.'.strtolower($file->extension()), [
            'disk' => $disk, 'visibility' => 'public',
        ]);
        [$w, $h] = @getimagesize($file->getRealPath()) ?: [null, null];

        $media = StoreMedia::create(['disk' => $disk, 'path' => $path, 'width' => $w, 'height' => $h, 'size' => $file->getSize()]);

        return response()->json(['data' => $media->toApi()], 201);
    }
}
