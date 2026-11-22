<?php

namespace App\I18n;

use App\Models\Store;
use App\Models\Translation;
use Illuminate\Support\Facades\App;

/**
 * The shopper's language for this request and the merchant's content translations.
 *
 * - Every store has a default language (stores.locale) served at "/", plus optional extra
 *   languages under a prefix ("/fr/products/…").
 * - Built-in storefront text uses Laravel's __() with lang/{code}.json (English is the source).
 * - Merchant content (product titles, pages, menus…) is translated per field in `translations`;
 *   models read it transparently while `translating()` is on (storefront, non-default language).
 */
class Localization
{
    private ?string $locale = null;

    private ?string $default = null;

    private bool $translating = false;

    /** Storefront request inside a market: URLs are prefixed "/{lang}-{market}" instead of "/{lang}". */
    private ?\App\Models\Market $market = null;

    /** @var array<string, array<int, array<string, string>>> type => id => field => value */
    private array $cache = [];

    /** @return array<string, string> code => native name */
    public static function languages(): array
    {
        return config('lumee.languages');
    }

    /** Languages written right to left. */
    public const RTL = ['ar', 'he', 'fa', 'ur'];

    /** "rtl" or "ltr" for a language (default: the current one). */
    public static function dir(?string $locale = null): string
    {
        $lang = strtolower(substr(str_replace('_', '-', $locale ?? app()->getLocale()), 0, 2));

        return in_array($lang, self::RTL, true) ? 'rtl' : 'ltr';
    }

    public static function storeDefault(Store $store): string
    {
        return array_key_exists($store->locale, self::languages()) ? $store->locale : 'en';
    }

    /** Default language first, then the extra languages the merchant published. */
    public static function enabled(Store $store): array
    {
        $extra = array_intersect((array) ($store->settings['languages'] ?? []), array_keys(self::languages()));

        return array_values(array_unique([self::storeDefault($store), ...$extra]));
    }

    public function begin(Store $store, string $locale, ?\App\Models\Market $market = null): void
    {
        $this->market = $market;
        $this->default = self::storeDefault($store);
        $this->locale = $locale;
        $this->translating = $locale !== $this->default;
        $this->cache = [];
        App::setLocale($locale);
    }

    /** Run $fn as if the shopper used $locale (e.g. sending an email), then restore everything. */
    public function within(Store $store, string $locale, callable $fn): mixed
    {
        $saved = [$this->locale, $this->default, $this->translating, $this->cache, App::getLocale(), $this->market];
        $this->begin($store, $locale, $this->market);
        try {
            return $fn();
        } finally {
            [$this->locale, $this->default, $this->translating, $this->cache, $appLocale, $this->market] = $saved;
            App::setLocale($appLocale);
        }
    }

    public function locale(): string
    {
        return $this->locale ?? App::getLocale();
    }

    public function isDefault(): bool
    {
        return $this->locale === null || $this->locale === $this->default;
    }

    /** "" for the default language, else "/fr"; inside a market "/fr-eu" (every language has a prefix there). */
    public function prefix(?string $locale = null): string
    {
        $locale ??= $this->locale;
        if ($this->market) {
            return $this->market->prefix($locale ?: null);
        }

        return ! $locale || $locale === $this->default ? '' : '/'.$locale;
    }

    /** Store path in a given language: url('/cart') → "/fr/cart". */
    public function url(string $path, ?string $locale = null): string
    {
        $prefix = $this->prefix($locale);

        return $prefix === '' ? $path : $prefix.($path === '/' ? '' : $path);
    }

    public function translating(): bool
    {
        return $this->translating;
    }

    /** Run something in the default language (e.g. snapshotting product titles onto an order). */
    public function withoutTranslation(callable $fn): mixed
    {
        $was = $this->translating;
        $this->translating = false;
        try {
            return $fn();
        } finally {
            $this->translating = $was;
        }
    }

    /** Translated value of one field, or null (caller falls back to the original). */
    public function get(string $type, int $id, string $field): ?string
    {
        if (! $this->translating) {
            return null;
        }
        if (! isset($this->cache[$type])) {
            // One query per resource type per request (store-scoped by the global scope)
            $this->cache[$type] = [];
            foreach (Translation::where('locale', $this->locale)->where('type', $type)->get(['resource_id', 'field', 'value']) as $t) {
                $this->cache[$type][$t->resource_id][$t->field] = $t->value;
            }
        }
        $v = $this->cache[$type][$id][$field] ?? null;

        return $v === null || trim($v) === '' ? null : $v;
    }

    /** Translate a whole value with a fallback in one call. */
    public function pick(string $type, int $id, string $field, mixed $original): mixed
    {
        return $this->get($type, $id, $field) ?? $original;
    }
}
