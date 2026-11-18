@extends($theme->view('layout'))

@section('title', ($page->seo['title'] ?? null) ?: $page->title.' – '.$store->name)
@section('description', ($page->seo['description'] ?? null) ?: ($page->excerpt() ?: $page->title))

@push('head')
<style>
    .page-wrap { max-width: 760px; margin: 0 auto; padding: 56px 16px 0; }
    .page-wrap h1.page-title { font-size: clamp(32px, 5vw, 48px); margin: 8px 0 32px; text-align: center; }
    .rte { line-height: 1.8; font-size: 16px; }
    .rte h2 { font-family: var(--font-display); font-size: 26px; margin: 36px 0 12px; font-weight: 600; }
    .rte h3 { font-size: 18px; margin: 28px 0 8px; }
    .rte p { margin: 0 0 16px; }
    .rte ul, .rte ol { padding-left: 22px; margin: 0 0 16px; }
    .rte li { margin-bottom: 6px; }
    .rte a { color: var(--rose-gold-700); text-decoration: underline; }
    .rte blockquote { margin: 0 0 16px; padding: 4px 0 4px 16px; border-left: 3px solid var(--rose-gold-300); color: var(--ink-muted); }
    .rte img { max-width: 100%; height: auto; border-radius: 8px; }
    .rte hr { border: 0; border-top: 1px solid var(--border); margin: 32px 0; }
    .rte table { border-collapse: collapse; width: 100%; margin-bottom: 16px; }
    .rte th, .rte td { border: 1px solid var(--border); padding: 8px 10px; text-align: left; }
</style>
@endpush

@section('content')
    <article class="page-wrap">
        <div class="eyebrow" style="text-align:center">{{ $store->name }}</div>
        <h1 class="display page-title">{{ $page->title }}</h1>
        {{-- Rendered from Markdown with raw HTML stripped and unsafe links removed (Page::html) --}}
        <div class="rte">{!! $page->html() !!}</div>
    </article>
@endsection
