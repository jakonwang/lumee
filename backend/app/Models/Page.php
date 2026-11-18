<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Page extends Model
{
    use BelongsToStore, \App\I18n\HasTranslations;

    public const TRANSLATION_TYPE = 'page';

    public const TRANSLATABLE = ['title', 'body', 'seo'];

    protected $fillable = ['store_id', 'title', 'handle', 'body', 'seo', 'published'];

    protected function casts(): array
    {
        return ['seo' => 'array', 'published' => 'boolean'];
    }

    public function scopePublished(Builder $q): Builder
    {
        return $q->where('published', true);
    }

    /**
     * Merchant-written Markdown → HTML. Raw HTML in the source is stripped and only safe
     * link schemes are kept, so a page can never inject scripts into the storefront.
     */
    public function html(): string
    {
        return Str::markdown((string) $this->body, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);
    }

    public function excerpt(int $length = 160): string
    {
        return Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($this->html()))), $length);
    }
}
