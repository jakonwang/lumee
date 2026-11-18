<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductImage;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ImageUploadController extends Controller
{
    public function __invoke(Request $request, CurrentStore $current): JsonResponse
    {
        $request->validate([
            // mimes checks real content (finfo), not just the extension
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240', 'dimensions:max_width=8000,max_height=8000'],
            'alt' => ['nullable', 'string', 'max:255'],
        ], [
            'file.mimes' => __('只支持 JPG / PNG / WebP / GIF 图片'),
            'file.max' => __('图片不能超过 10MB'),
        ]);

        $file = $request->file('file');
        $disk = config('lumee.media_disk');
        $ext = strtolower($file->extension());
        $path = $file->storeAs(
            'stores/'.$current->id().'/products',
            Str::uuid().'.'.$ext,
            ['disk' => $disk, 'visibility' => 'public'],
        );

        [$w, $h] = @getimagesize($file->getRealPath()) ?: [null, null];

        $image = ProductImage::create([
            'disk' => $disk,
            'path' => $path,
            'alt' => $request->input('alt'),
            'width' => $w,
            'height' => $h,
            'size' => $file->getSize(),
        ]);

        return response()->json(['data' => $image->toApi()], 201);
    }
}
