<?php

namespace App\Collect;

use App\Models\Product;
use App\Models\ProductSource;

/** Is this already in the catalog? Same source page, or an almost identical title. */
class Duplicates
{
    /** @return array{product_id: int, title: string, reason: string}|null */
    public function find(string $url, string $title): ?array
    {
        $source = ProductSource::where('url_hash', Item::hash($url))->latest('id')->first();
        if ($source && ($p = Product::find($source->product_id))) {
            return ['product_id' => $p->id, 'title' => $p->title, 'reason' => 'same_source'];
        }

        $norm = self::norm($title);
        $words = array_values(array_filter(explode(' ', $norm), fn ($w) => mb_strlen($w) >= 4));
        if ($norm === '' || ! $words) {
            return null;
        }
        // Candidates share the longest word; then compare whole titles
        usort($words, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach (Product::where('title', 'like', '%'.addcslashes($words[0], '%_\\').'%')->limit(200)->get(['id', 'title']) as $p) {
            similar_text($norm, self::norm($p->title), $pct);
            if ($pct >= 90) {
                return ['product_id' => $p->id, 'title' => $p->title, 'reason' => 'similar_title'];
            }
        }

        return null;
    }

    private static function norm(string $t): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($t))));
    }
}
