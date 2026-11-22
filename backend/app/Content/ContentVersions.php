<?php

namespace App\Content;

use App\Models\Product;
use App\Models\ProductContentVersion;
use App\Models\Translation;
use Illuminate\Support\Facades\DB;

/**
 * Title / description / SEO of a product in one language (null = the default language, stored on
 * the product; others are translations), with a snapshot saved before every change so any
 * earlier version can be restored.
 */
class ContentVersions
{
    /** @return array{title: ?string, description: ?string, seo: array{title?: string, description?: string}} */
    public static function current(Product $product, ?string $locale): array
    {
        if ($locale === null) {
            $seo = $product->getRawOriginal('seo');

            return ['title' => $product->getRawOriginal('title'), 'description' => $product->getRawOriginal('description'),
                'seo' => is_array($seo) ? $seo : (array) json_decode((string) $seo, true)];
        }
        $t = Translation::where('type', 'product')->where('resource_id', $product->id)->where('locale', $locale)->pluck('value', 'field');

        return ['title' => $t['title'] ?? null, 'description' => $t['description'] ?? null,
            'seo' => array_filter(['title' => $t['seo_title'] ?? null, 'description' => $t['seo_description'] ?? null])];
    }

    /** Save new content for a language, keeping the previous version in the history. */
    public function apply(Product $product, ?string $locale, array $new, string $reason): void
    {
        DB::transaction(function () use ($product, $locale, $new, $reason) {
            $old = self::current($product, $locale);
            if ($old['title'] !== null || $old['description'] !== null) {
                ProductContentVersion::create([
                    'product_id' => $product->id, 'locale' => $locale, 'title' => $old['title'], 'description' => $old['description'],
                    'seo' => $old['seo'] ?: null, 'reason' => $reason, 'user_id' => auth()->id(), 'created_at' => now(),
                ]);
            }
            $seo = array_filter(['title' => $new['seo_title'] ?? null, 'description' => $new['seo_description'] ?? null], fn ($v) => $v !== null && $v !== '');

            if ($locale === null) {
                $product->forceFill(array_filter([
                    'title' => $new['title'] ?? null,
                    'description' => $new['description'] ?? null,
                ], fn ($v) => $v !== null));
                if ($seo) {
                    $product->seo = [...(array) ($old['seo'] ?? []), ...$seo];
                }
                $product->save();

                return;
            }
            $fields = array_filter(['title' => $new['title'] ?? null, 'description' => $new['description'] ?? null,
                'seo_title' => $seo['title'] ?? null, 'seo_description' => $seo['description'] ?? null], fn ($v) => $v !== null);
            foreach ($fields as $field => $value) {
                Translation::updateOrCreate(['type' => 'product', 'resource_id' => $product->id, 'locale' => $locale, 'field' => $field], ['value' => $value]);
            }
        });
    }

    public function restore(Product $product, ProductContentVersion $v): void
    {
        $this->apply($product, $v->locale, ['title' => $v->title, 'description' => $v->description,
            'seo_title' => $v->seo['title'] ?? null, 'seo_description' => $v->seo['description'] ?? null], 'before_restore');
    }
}
