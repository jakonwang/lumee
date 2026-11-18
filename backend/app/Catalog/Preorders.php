<?php

namespace App\Catalog;

use App\Models\Product;
use App\Models\ProductVariant;

/**
 * Pre-orders: keep selling a product when it's out of stock. Stock goes negative (the merchant's
 * backlog); an optional limit caps how many can be pre-ordered per variant. The product page says
 * "Pre-order" with the expected ship date, and order lines sold beyond stock are marked as pre-orders.
 */
class Preorders
{
    public const DEFAULTS = ['enabled' => false, 'ships_at' => null, 'note' => null, 'limit' => null];

    public static function config(?Product $p): array
    {
        $c = array_merge(self::DEFAULTS, array_intersect_key((array) ($p?->preorder ?? []), self::DEFAULTS));
        $c['enabled'] = (bool) $c['enabled'];

        return $c;
    }

    public static function clean(?array $in): ?array
    {
        if (! $in || empty($in['enabled'])) {
            return null;
        }

        return [
            'enabled' => true,
            'ships_at' => ! empty($in['ships_at']) ? substr((string) $in['ships_at'], 0, 10) : null,
            'note' => isset($in['note']) ? mb_substr(trim((string) $in['note']), 0, 200) ?: null : null,
            'limit' => isset($in['limit']) && $in['limit'] !== '' && $in['limit'] !== null ? max(1, (int) $in['limit']) : null,
        ];
    }

    /** How many of this variant can be sold right now (null = unlimited). */
    public static function available(ProductVariant $v): ?int
    {
        $c = self::config($v->product);
        if (! $c['enabled']) {
            return max(0, (int) $v->inventory_quantity);
        }

        return $c['limit'] === null ? null : max(0, (int) $v->inventory_quantity + $c['limit']);
    }

    public static function allows(ProductVariant $v, int $qty): bool
    {
        $a = self::available($v);

        return $a === null || $qty <= $a;
    }

    /** True when (part of) this quantity would be sold beyond stock as a pre-order. */
    public static function isPreorder(ProductVariant $v, int $qty): bool
    {
        return self::config($v->product)['enabled'] && $qty > (int) $v->inventory_quantity;
    }
}
