<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A business buyer: its people (customers), ship-to locations, payment terms and tax status. */
class Company extends Model
{
    use BelongsToStore;

    protected $fillable = ['name', 'tax_id', 'tag', 'payment_terms', 'tax_exempt', 'locations', 'note'];

    protected function casts(): array
    {
        return ['payment_terms' => 'integer', 'tax_exempt' => 'boolean', 'locations' => 'array'];
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}
