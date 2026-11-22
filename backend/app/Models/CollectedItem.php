<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A collected product waiting in the review pool. data/original follow App\Collect\Item. */
class CollectedItem extends Model
{
    use BelongsToStore;

    public const STATUSES = ['pending', 'ready', 'failed', 'published', 'rejected'];

    protected $fillable = ['url', 'url_hash', 'platform', 'status', 'data', 'original', 'checks', 'error', 'product_id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'original' => 'array', 'checks' => 'array'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
