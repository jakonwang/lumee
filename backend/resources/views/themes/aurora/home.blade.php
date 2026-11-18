@extends($theme->view('layout'))

@section('title', $store->settings['seo']['title'] ?? $store->name)
@section('description', $store->settings['seo']['description'] ?? $store->name)
@if ($theme->logoUrl())
    @section('og_image', $theme->logoUrl())
@endif

@push('head')
<style>
    .section { padding-block: 72px; }
    .section-head { text-align: center; margin-bottom: 40px; }
    .section-head h2 { font-size: clamp(28px, 4vw, 38px); margin: 8px 0 0; }
    .prose { color: var(--ink-muted); line-height: 1.8; white-space: pre-line; }
    @media (max-width: 720px) { .section { padding-block: 48px; } }
</style>
@endpush

@section('content')
    @forelse ($theme->sections() as $section)
        @includeIf($theme->view('sections.'.$section['type']), ['section' => $section, 's' => $section['settings']])
    @empty
        <div class="wrap section" style="text-align:center;color:var(--ink-muted)">{{ __('This store is getting ready. Check back soon.') }}</div>
    @endforelse
@endsection
