<?php

namespace App\Shipping;

use App\Models\ShippingRate;
use App\Models\Store;

/**
 * Local pickup and digital delivery as checkout "shipping" options. They're unsaved ShippingRate
 * objects with reserved negative ids, so the rest of checkout (selection, totals, tax, the order's
 * shipping_method) treats them like any rate:
 *   -1        digital delivery (cart has only digital products)
 *   -100 - i  pickup at location i
 */
class Pickup
{
    public const DIGITAL_ID = -1;

    public const MAX_LOCATIONS = 10;

    public static function settings(Store $store): array
    {
        $s = $store->settings['pickup'] ?? [];

        return [
            'enabled' => (bool) ($s['enabled'] ?? false),
            'locations' => array_values(array_slice((array) ($s['locations'] ?? []), 0, self::MAX_LOCATIONS)),
        ];
    }

    /** @return ShippingRate[] */
    public static function rates(Store $store): array
    {
        $s = self::settings($store);
        if (! $s['enabled']) {
            return [];
        }
        $out = [];
        foreach ($s['locations'] as $i => $loc) {
            $r = new ShippingRate(['name' => __('Pick up at :place', ['place' => $loc['name']]), 'price' => 0]);
            $r->id = -100 - $i;
            $r->description = trim(($loc['address'] ?? '').(! empty($loc['hours']) ? ' · '.$loc['hours'] : ''));
            $out[] = $r;
        }

        return $out;
    }

    public static function digital(): ShippingRate
    {
        $r = new ShippingRate(['name' => __('Digital delivery'), 'price' => 0]);
        $r->id = self::DIGITAL_ID;
        $r->description = __('Download links are emailed after payment');

        return $r;
    }

    /** The pickup location behind a rate id, or null. */
    public static function location(Store $store, int $rateId): ?array
    {
        if ($rateId > -100) {
            return null;
        }

        return self::settings($store)['locations'][-100 - $rateId] ?? null;
    }
}
