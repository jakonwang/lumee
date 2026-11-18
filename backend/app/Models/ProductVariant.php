<?php

namespace App\Models;

use App\Support\Money;
use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    use BelongsToStore;

    /** Stock set through the editor, CSV import or API saves is logged (atomic changes use Services\Inventory). */
    protected static function booted(): void
    {
        static::saved(function (ProductVariant $v) {
            $qty = (int) $v->inventory_quantity;
            if ($v->wasRecentlyCreated) {
                $qty !== 0 && \App\Services\Inventory::log($v, $qty, \App\Services\Inventory::currentReason() === 'edit' ? 'created' : \App\Services\Inventory::currentReason());
            } elseif ($v->wasChanged('inventory_quantity')) {
                $before = (int) $v->getOriginal('inventory_quantity');
                \App\Services\Inventory::log($v, $qty - $before, \App\Services\Inventory::currentReason());
                rescue(fn () => app(\App\Marketing\StockAlerts::class)->restocked($v->id, $before, $qty));   // back-in-stock emails
            }
        });
    }

    protected $fillable = [
        'product_id', 'image_id', 'title', 'sku', 'price', 'compare_at_price',
        'inventory_quantity', 'weight', 'options', 'position',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'price' => 'integer',
            'compare_at_price' => 'integer',
            'inventory_quantity' => 'integer',
            'weight' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(ProductImage::class, 'image_id');
    }

    /** Storefront in another language: option names/values (and the title built from them) translated. */
    public function getAttribute($key)
    {
        $value = parent::getAttribute($key);

        // Storefront: an active sale or the customer's wholesale price lowers the price (the lower wins);
        // the original becomes the crossed-out price
        if (($key === 'price' || $key === 'compare_at_price') && $this->exists) {
            $promos = app(\App\Promotions\Promotions::class);
            if ($promos->enabled()) {
                $raw = (int) parent::getAttribute('price');
                $sale = $promos->salePrice((int) parent::getAttribute('product_id'), $raw);
                $best = min($sale, app(\App\Pricing\PriceLists::class)->price((int) parent::getAttribute('id'), $raw));
                if ($best !== $raw) {
                    return $key === 'price' ? $best : max($raw, (int) parent::getAttribute('compare_at_price'));
                }
            }

            return $value;
        }

        $l10n = app(\App\I18n\Localization::class);
        if (! $l10n->translating() || ! in_array($key, ['options', 'title'], true) || ! $this->exists) {
            return $value;
        }
        $options = (array) parent::getAttribute('options');
        if (! $options) {
            return $value;   // single-variant product ("Default")
        }
        $pid = (int) $this->product_id;
        $translated = [];
        foreach ($options as $name => $v) {
            $translated[$l10n->pick('product', $pid, "option.{$name}", $name)] = $l10n->pick('product', $pid, "option.{$name}.{$v}", $v);
        }

        return $key === 'options' ? $translated : implode(' / ', $translated);
    }

    public function formattedPrice(): string
    {
        return Money::format($this->price);
    }
}
