<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    use BelongsToStore;

    protected $fillable = ['product_id', 'disk', 'path', 'alt', 'width', 'height', 'size', 'position'];

    protected function casts(): array
    {
        return ['width' => 'integer', 'height' => 'integer', 'size' => 'integer', 'position' => 'integer', 'processed' => 'array'];
    }

    protected static function booted(): void
    {
        // Remove the file when the record goes away
        static::deleted(function (ProductImage $img) {
            Storage::disk($img->disk)->delete(array_filter([$img->path, $img->original_path]));
            \App\Media\Img::forget($img->disk, $img->path);   // the storefront sizes made from it
        });
        // Replaced file (content workshop standardize / revert): its old sizes are no longer used
        static::updated(fn (ProductImage $img) => $img->wasChanged('path') ? \App\Media\Img::forget($img->disk, $img->getOriginal('path')) : null);
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url(),
            'alt' => $this->alt,
            'width' => $this->width,
            'height' => $this->height,
            'processed' => (bool) $this->original_path,   // standardized by the content workshop (revertable)
            'ai' => $this->processed['ai'] ?? null,         // "scene": made by an image generation service
        ];
    }
}
