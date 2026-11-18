<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class StoreMedia extends Model
{
    use BelongsToStore;

    protected $table = 'store_media';

    protected $fillable = ['disk', 'path', 'width', 'height', 'size'];

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function toApi(): array
    {
        return ['id' => $this->id, 'url' => $this->url(), 'width' => $this->width, 'height' => $this->height];
    }
}
