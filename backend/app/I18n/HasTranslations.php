<?php

namespace App\I18n;

/**
 * Reads translated attributes on the storefront (non-default language) without template
 * changes: $product->title returns the French title when the shopper browses /fr.
 * Saving and API serialization use the raw attributes, so the admin always sees the original.
 *
 * Using class defines: TRANSLATION_TYPE and TRANSLATABLE (attribute names; "seo" maps to
 * the seo_title / seo_description fields).
 */
trait HasTranslations
{
    public function getAttribute($key)
    {
        $value = parent::getAttribute($key);
        if (! in_array($key, static::TRANSLATABLE, true) || ! $this->exists) {
            return $value;
        }
        $l10n = app(Localization::class);
        if (! $l10n->translating()) {
            return $value;
        }
        if ($key === 'seo') {
            $seo = is_array($value) ? $value : [];
            foreach (['title', 'description'] as $f) {
                if (($t = $l10n->get(static::TRANSLATION_TYPE, $this->getKey(), "seo_{$f}")) !== null) {
                    $seo[$f] = $t;
                }
            }

            return $seo ?: $value;
        }

        return $l10n->get(static::TRANSLATION_TYPE, $this->getKey(), $key) ?? $value;
    }
}
