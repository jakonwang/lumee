<?php

namespace App\Tenancy;

use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scopes every query to the current store and stamps store_id on create.
 * Every store-level model MUST use this trait — it is the tenant isolation boundary.
 */
trait BelongsToStore
{
    public static function bootBelongsToStore(): void
    {
        static::addGlobalScope('store', function (Builder $query) {
            $current = app(CurrentStore::class);
            if ($current->check()) {
                $query->where($query->getModel()->getTable().'.store_id', $current->id());
            }
        });

        static::creating(function ($model) {
            if (empty($model->store_id)) {
                $model->store_id = app(CurrentStore::class)->getOrFail()->id;
            }
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
