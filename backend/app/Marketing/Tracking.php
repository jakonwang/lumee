<?php

namespace App\Marketing;

use App\Models\Store;

/**
 * Store analytics / ad pixels, kept in stores.settings['tracking']:
 *   ga4        Google Analytics 4 measurement id   G-XXXXXXX
 *   meta       Meta (Facebook) Pixel id            digits
 *   tiktok     TikTok Pixel id                     letters/digits
 *   consent    true = show a cookie banner and load nothing until the shopper accepts (EU/UK)
 *
 * Ids are validated strictly (they are printed into <script>), and the storefront fires the
 * standard e-commerce events: page view, view item, add to cart, begin checkout, purchase.
 */
final class Tracking
{
    public const DEFAULTS = ['ga4' => null, 'meta' => null, 'tiktok' => null, 'consent' => false];

    public const PATTERNS = [
        'ga4' => '/^G-[A-Z0-9]{4,20}$/',
        'meta' => '/^\d{6,20}$/',
        'tiktok' => '/^[A-Z0-9]{10,30}$/',
    ];

    public function __construct(private array $settings) {}

    public static function for(Store $store): self
    {
        $saved = $store->settings['tracking'] ?? [];
        $s = array_merge(self::DEFAULTS, array_intersect_key($saved, self::DEFAULTS));
        foreach (self::PATTERNS as $key => $re) {
            $s[$key] = is_string($s[$key]) && preg_match($re, $s[$key]) ? $s[$key] : null;   // never print anything unvalidated
        }
        $s['consent'] = (bool) $s['consent'];

        return new self($s);
    }

    public static function save(Store $store, array $data): void
    {
        $settings = $store->settings ?? [];
        $settings['tracking'] = [
            'ga4' => self::clean($data['ga4'] ?? null, true),
            'meta' => self::clean($data['meta'] ?? null),
            'tiktok' => self::clean($data['tiktok'] ?? null, true),
            'consent' => (bool) ($data['consent'] ?? false),
        ];
        $store->settings = $settings;
        $store->save();
    }

    public static function clean(?string $v, bool $upper = false): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : ($upper ? strtoupper($v) : $v);
    }

    public function all(): array
    {
        return $this->settings;
    }

    public function any(): bool
    {
        return (bool) ($this->settings['ga4'] || $this->settings['meta'] || $this->settings['tiktok']);
    }

    public function get(string $key): mixed
    {
        return $this->settings[$key] ?? null;
    }
}
