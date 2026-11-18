<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A buyer's download link for one file of a paid order (limited number of downloads, expires). */
class OrderDownload extends Model
{
    use BelongsToStore;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'last_downloaded_at' => 'datetime', 'downloads' => 'integer', 'max_downloads' => 'integer'];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(ProductFile::class, 'product_file_id');
    }

    public function usable(): bool
    {
        return $this->downloads < $this->max_downloads && (! $this->expires_at || $this->expires_at->isFuture());
    }
}
