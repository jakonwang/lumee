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
        return ['width' => 'integer', 'height' => 'integer', 'size' => 'integer', 'position' => 'integer'];
    }

    protected static function booted(): void
    {
        // Remove the file when the record goes away
        static::deleted(fn (ProductImage $img) => Storage::disk($img->disk)->delete($img->path));
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
        ];
    }
}
