<?php

namespace App\Models;

use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BlogPost extends Model
{
    use BelongsToStore, \App\I18n\HasTranslations;

    public const TRANSLATION_TYPE = 'post';

    public const TRANSLATABLE = ['title', 'excerpt', 'body', 'seo'];

    protected $fillable = ['title', 'handle', 'excerpt', 'body', 'cover_image', 'author', 'tags', 'seo', 'published', 'published_at'];

    protected function casts(): array
    {
        return ['tags' => 'array', 'seo' => 'array', 'published' => 'boolean', 'published_at' => 'datetime'];
    }

    /** Live on the storefront: published, and the publish date has come (posts can be scheduled). */
    public function scopeLive(Builder $q): Builder
    {
        return $q->where('published', true)->where(fn ($w) => $w->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

    /** Same safe Markdown renderer as pages (raw HTML stripped, unsafe links removed). */
    public function html(): string
    {
        return (new Page(['body' => (string) $this->body]))->html();
    }

    /** The merchant's excerpt, else the start of the post. */
    public function summary(int $length = 200): string
    {
        $excerpt = trim((string) $this->excerpt);

        return $excerpt !== '' ? Str::limit($excerpt, $length) : Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($this->html()))), $length);
    }

    public function readingMinutes(): int
    {
        return max(1, (int) ceil(str_word_count(strip_tags($this->html())) / 220));
    }
}
