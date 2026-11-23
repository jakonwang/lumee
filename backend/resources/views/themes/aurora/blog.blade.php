@extends($theme->view('layout'))

@section('title', ($tag ? $tag.' – ' : '').__('Blog').' – '.$store->name)
@section('description', __('Stories, guides and news from :store.', ['store' => $store->name]))

@push('head')
<link rel="alternate" type="application/atom+xml" title="{{ $store->name }} — {{ __('Blog') }}" href="/blog/feed.xml">
<style>
    .blog-head { text-align: center; padding: 56px 0 24px; }
    .blog-head h1 { font-size: clamp(32px, 5vw, 48px); margin: 8px 0 0; }
    .blog-tags { display: flex; flex-wrap: wrap; justify-content: center; gap: 8px; margin: 0 0 32px; }
    .blog-tags a { padding: 6px 14px; border-radius: 999px; border: 1px solid var(--border); font-size: 13px; text-decoration: none; }
    .blog-tags a[aria-current="true"] { background: var(--rose-gold-500); border-color: transparent; color: var(--on-primary); }
    .post-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 32px; padding-bottom: 56px; }
    .post-card { text-decoration: none; display: grid; gap: 10px; align-content: start; }
    .post-card .cover { aspect-ratio: 4 / 3; border-radius: var(--radius-card); background: var(--surface-muted); overflow: hidden; }
    .post-card .cover img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform .4s; }
    .post-card:hover .cover img { transform: scale(1.03); }
    .post-card h2 { font-size: 22px; margin: 0; line-height: 1.3; }
    .post-meta { font-size: 13px; color: var(--ink-muted); }
    .post-card p { margin: 0; color: var(--ink-muted); line-height: 1.6; font-size: 15px; }
    .blog-empty { text-align: center; color: var(--ink-muted); padding: 48px 0 80px; }
    .blog-pages { display: flex; justify-content: center; gap: 16px; padding-bottom: 64px; }
    @media (max-width: 900px) { .post-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 600px) { .post-grid { grid-template-columns: 1fr; } }
</style>
@endpush

@section('content')
    <div class="wrap">
        <header class="blog-head">
            <div class="eyebrow">{{ $store->name }}</div>
            <h1 class="display">{{ $tag ?: __('Blog') }}</h1>
        </header>

        @if ($tags->isNotEmpty())
            <nav class="blog-tags" aria-label="{{ __('Topics') }}">
                <a href="/blog" @if (! $tag) aria-current="true" @endif>{{ __('All') }}</a>
                @foreach ($tags as $t)
                    <a href="/blog?tag={{ urlencode($t) }}" @if ($tag === $t) aria-current="true" @endif>{{ $t }}</a>
                @endforeach
            </nav>
        @endif

        @if ($posts->isEmpty())
            <p class="blog-empty">{{ __('No posts yet. Check back soon.') }}</p>
        @else
            <div class="post-grid">
                @foreach ($posts as $post)
                    <a class="post-card" href="/blog/{{ $post->handle }}">
                        <div class="cover">
                            @if ($post->cover_image)<img {{ \App\Media\Img::attrs($post->cover_image, 'card') }} alt="" loading="lazy">@endif
                        </div>
                        <div class="post-meta">{{ $post->published_at?->translatedFormat('j M Y') }} · {{ trans_choice(':count min read|:count min read', $post->readingMinutes(), ['count' => $post->readingMinutes()]) }}</div>
                        <h2 class="display">{{ $post->title }}</h2>
                        <p>{{ $post->summary(160) }}</p>
                    </a>
                @endforeach
            </div>
            @if ($posts->hasPages())
                <nav class="blog-pages" aria-label="{{ __('Pages') }}">
                    @if ($posts->previousPageUrl())<a href="{{ $posts->previousPageUrl() }}">← {{ __('Newer posts') }}</a>@endif
                    @if ($posts->nextPageUrl())<a href="{{ $posts->nextPageUrl() }}">{{ __('Older posts') }} →</a>@endif
                </nav>
            @endif
        @endif
    </div>
@endsection
