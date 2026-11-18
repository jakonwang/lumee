<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class ProductOption extends Model
{
    use BelongsToStore;

    protected $fillable = ['product_id', 'name', 'values', 'position'];

    protected function casts(): array
    {
        return ['values' => 'array'];
    }

    /** Storefront in another language: option name and values from the product's translations. */
    public function getAttribute($key)
    {
        $value = parent::getAttribute($key);
        $l10n = app(\App\I18n\Localization::class);
        if (! $l10n->translating() || ! in_array($key, ['name', 'values'], true) || ! $this->exists) {
            return $value;
        }
        $name = parent::getAttribute('name');
        if ($key === 'name') {
            return $l10n->pick('product', (int) $this->product_id, "option.{$name}", $value);
        }

        return array_map(fn ($v) => $l10n->pick('product', (int) $this->product_id, "option.{$name}.{$v}", $v), (array) $value);
    }
}
