@extends($theme->view('layout'))

@section('title', ($post->seo['title'] ?? null) ?: $post->title.' – '.$store->name)
@section('description', ($post->seo['description'] ?? null) ?: $post->summary(160))
@section('og_type', 'article')

@php
    $abs = fn ($u) => str_starts_with((string) $u, '/') ? $store->storefrontUrl().$u : $u;
    $articleLd = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'BlogPosting',
        'headline' => \Illuminate\Support\Str::limit($post->title, 110, ''),
        'description' => $post->summary(160),
        'image' => $post->cover_image ? [$abs($post->cover_image)] : null,
        'datePublished' => $post->published_at?->toAtomString(),
        'dateModified' => $post->updated_at?->toAtomString(),
        'author' => ['@type' => $post->author ? 'Person' : 'Organization', 'name' => $post->author ?: $store->name],
        'publisher' => ['@type' => 'Organization', 'name' => $store->name],
        'mainEntityOfPage' => $store->storefrontUrl().'/blog/'.$post->handle,
    ]);
@endphp

@push('head')
@if ($post->cover_image)<meta property="og:image" content="{{ $abs($post->cover_image) }}">@endif
<script type="application/ld+json">{!! json_encode($articleLd, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
<style>
    .article { max-width: 760px; margin: 0 auto; padding: 48px 16px 0; }
    .article .back { font-size: 13px; color: var(--ink-muted); text-decoration: none; }
    .article h1 { font-size: clamp(32px, 5vw, 46px); margin: 12px 0 12px; line-height: 1.2; }
    .article .meta { color: var(--ink-muted); font-size: 14px; margin-bottom: 28px; }
    .article .cover { border-radius: var(--radius-card); overflow: hidden; margin-bottom: 32px; }
    .article .cover img { width: 100%; height: auto; display: block; }
    .article .tags { display: flex; flex-wrap: wrap; gap: 8px; margin: 32px 0 0; }
    .article .tags a { padding: 4px 12px; border-radius: 999px; border: 1px solid var(--border); font-size: 13px; text-decoration: none; }
    .rte { line-height: 1.8; font-size: 17px; }
    .rte h2 { font-family: var(--font-display); font-size: 28px; margin: 40px 0 12px; font-weight: 600; }
    .rte h3 { font-size: 20px; margin: 28px 0 8px; }
    .rte p { margin: 0 0 18px; }
    .rte ul, .rte ol { padding-inline-start: 22px; margin: 0 0 18px; }
    .rte a { color: var(--rose-gold-700); text-decoration: underline; }
    .rte blockquote { margin: 0 0 18px; padding: 4px 0 4px 16px; border-inline-start: 3px solid var(--rose-gold-300); color: var(--ink-muted); }
    .rte img { max-width: 100%; height: auto; border-radius: 8px; }
    .related { max-width: 1100px; margin: 64px auto 0; padding: 40px 16px 64px; border-top: 1px solid var(--border); }
    .related h2 { font-size: 26px; margin: 0 0 24px; text-align: center; }
    .related-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 24px; }
    .related-grid a { text-decoration: none; display: grid; gap: 8px; }
    .related-grid .cover { aspect-ratio: 4 / 3; border-radius: var(--radius-card); background: var(--surface-muted); overflow: hidden; }
    .related-grid img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .related-grid span { color: var(--ink-muted); font-size: 13px; }
    @media (max-width: 700px) { .related-grid { grid-template-columns: 1fr; } }
</style>
@endpush

@section('content')
    <article class="article">
        <a class="back" href="/blog">← {{ __('Blog') }}</a>
        <h1 class="display">{{ $post->title }}</h1>
        <div class="meta">
            {{ $post->published_at?->translatedFormat('j F Y') }}
            @if ($post->author) · {{ __('By :name', ['name' => $post->author]) }}@endif
            · {{ trans_choice(':count min read|:count min read', $post->readingMinutes(), ['count' => $post->readingMinutes()]) }}
        </div>
        @if ($post->cover_image)
            <div class="cover"><img {{ \App\Media\Img::attrs($post->cover_image, 'hero') }} alt="" fetchpriority="high"></div>
        @endif
        {{-- Markdown with raw HTML stripped and unsafe links removed (BlogPost::html) --}}
        <div class="rte">{!! $post->html() !!}</div>
        @if ($post->tags)
            <div class="tags">
                @foreach ($post->tags as $t)
                    <a href="/blog?tag={{ urlencode($t) }}">#{{ $t }}</a>
                @endforeach
            </div>
        @endif
    </article>

    @if ($related->isNotEmpty())
        <section class="related" aria-labelledby="related-title">
            <h2 id="related-title" class="display">{{ __('More from the blog') }}</h2>
            <div class="related-grid">
                @foreach ($related as $r)
                    <a href="/blog/{{ $r->handle }}">
                        <div class="cover">@if ($r->cover_image)<img {{ \App\Media\Img::attrs($r->cover_image, 'card') }} alt="" loading="lazy">@endif</div>
                        <span>{{ $r->published_at?->translatedFormat('j M Y') }}</span>
                        <strong>{{ $r->title }}</strong>
                    </a>
                @endforeach
            </div>
        </section>
    @endif
@endsection
