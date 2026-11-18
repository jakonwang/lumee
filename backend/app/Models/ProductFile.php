<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** A downloadable file of a digital product (kept on the private disk, never public). */
class ProductFile extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'product_id', 'path', 'filename', 'size'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function toApi(): array
    {
        return ['id' => $this->id, 'filename' => $this->filename, 'size' => $this->size, 'created_at' => $this->created_at];
    }

    protected static function booted(): void
    {
        static::deleted(fn (ProductFile $f) => Storage::disk('local')->delete($f->path));
    }
}
