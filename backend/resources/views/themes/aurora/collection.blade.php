@extends($theme->view('layout'))

@section('title', ($collection->seo['title'] ?? null) ?: $collection->title.' – '.$store->name)
@section('description', ($collection->seo['description'] ?? null) ?: (\App\Support\Seo::text($collection->description) ?: $collection->title))
@if (method_exists($collection, 'imageUrl') && $collection->exists && $collection->imageUrl())
    @section('og_image', $collection->imageUrl())
@endif

@push('head')
<style>
    .coll-head { text-align: center; padding: 56px 0 32px; }
    .coll-head h1 { font-size: clamp(32px, 5vw, 52px); margin: 8px 0 12px; }
    .coll-head p { color: var(--ink-muted); max-width: 640px; margin: 0 auto; line-height: 1.7; white-space: pre-line; }
    .toolbar { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 24px; font-size: 13px; color: var(--ink-muted); flex-wrap: wrap; }
    .toolbar select { padding: 8px 12px; border-radius: 999px; border: 1px solid var(--border); background: var(--surface); }
    .pager { display: flex; justify-content: center; gap: 8px; margin-top: 48px; }
    .pager a, .pager span { min-width: 40px; padding: 8px 12px; text-align: center; border-radius: 999px; border: 1px solid var(--border); font-size: 14px; }
    .pager .current { background: var(--rose-gold-500); color: var(--on-primary); border-color: var(--rose-gold-500); }
    .pager .disabled { opacity: .4; }
</style>
@endpush

@section('content')
    {{-- Blocks of the collection page template (theme editor › 分类页); the product grid is always there --}}
    @include($theme->view('partials.blocks'), ['blocks' => $theme->templateSections('collection')])
@endsection
