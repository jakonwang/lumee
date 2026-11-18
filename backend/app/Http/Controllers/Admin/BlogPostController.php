<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use App\Support\Seo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Blog posts (Markdown body, cover image, tags, scheduled publishing). */
class BlogPostController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));
        $page = BlogPost::when($q !== '', fn ($w) => $w->where('title', 'like', '%'.addcslashes($q, '%_\\').'%'))
            ->orderByDesc('published_at')->orderByDesc('id')->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (BlogPost $p) => $this->summary($p)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->detail(BlogPost::findOrFail($id))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);
        $post = new BlogPost;
        $this->fill($post, $data);
        $post->handle = $this->uniqueHandle($data['handle'] ?? $data['title']);
        $post->save();

        return response()->json(['data' => $this->detail($post)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $post = BlogPost::findOrFail($id);
        $data = $this->validated($request, false);
        $this->fill($post, $data);
        if (array_key_exists('handle', $data)) {
            $post->handle = $this->uniqueHandle($data['handle'] ?: $post->title, $post->id);
        }
        $post->save();

        return response()->json(['data' => $this->detail($post)]);
    }

    public function destroy(int $id): Response
    {
        BlogPost::findOrFail($id)->delete();

        return response()->noContent();
    }

    private function fill(BlogPost $post, array $data): void
    {
        $post->fill(collect($data)->only('title', 'excerpt', 'body', 'cover_image', 'author', 'published')->all());
        if (array_key_exists('tags', $data)) {
            $post->tags = collect($data['tags'] ?? [])->map(fn ($t) => trim(mb_substr((string) $t, 0, 40)))->filter()->unique(fn ($t) => mb_strtolower($t))->take(10)->values()->all() ?: null;
        }
        if (array_key_exists('seo', $data)) {
            $post->seo = Seo::clean($data['seo']);
        }
        if (array_key_exists('published_at', $data)) {
            $post->published_at = $data['published_at'] ? Carbon::parse($data['published_at']) : null;
        }
        // First publish without a date → now
        if ($post->published && ! $post->published_at) {
            $post->published_at = now();
        }
    }

    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'handle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'excerpt' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'body' => ['sometimes', 'nullable', 'string', 'max:200000'],
            'cover_image' => ['sometimes', 'nullable', 'string', 'max:1000', 'regex:#^(https?://|/)#i'],
            'author' => ['sometimes', 'nullable', 'string', 'max:80'],
            'tags' => ['sometimes', 'nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:40'],
            'published' => ['sometimes', 'boolean'],
            'published_at' => ['sometimes', 'nullable', 'date'],
            'seo' => ['sometimes', 'nullable', 'array'],
            'seo.title' => ['nullable', 'string', 'max:70'],
            'seo.description' => ['nullable', 'string', 'max:320'],
        ], ['title.required' => __('请填写文章标题'), 'cover_image.regex' => __('封面图片地址不正确')]);
    }

    private function summary(BlogPost $p): array
    {
        return [
            'id' => $p->id,
            'title' => $p->title,
            'handle' => $p->handle,
            'author' => $p->author,
            'tags' => $p->tags ?? [],
            'cover_image' => $p->cover_image,
            'published' => $p->published,
            'published_at' => $p->published_at,
            'scheduled' => $p->published && $p->published_at?->isFuture(),
            'updated_at' => $p->updated_at,
        ];
    }

    private function detail(BlogPost $p): array
    {
        return [
            ...$this->summary($p),
            'excerpt' => (string) $p->excerpt,
            'body' => (string) $p->body,
            'seo' => ['title' => $p->seo['title'] ?? null, 'description' => $p->seo['description'] ?? null],
        ];
    }

    private function uniqueHandle(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'post-'.substr(md5($source), 0, 6);
        $handle = $base;
        $i = 2;
        while (BlogPost::where('handle', $handle)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $handle = $base.'-'.$i++;
        }

        return $handle;
    }
}
