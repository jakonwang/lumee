@extends($theme->view('layout'))

@section('title', $store->settings['seo']['title'] ?? $store->name)
@section('description', $store->settings['seo']['description'] ?? $store->name)
@if ($theme->logoUrl())
    @section('og_image', $theme->logoUrl())
@endif

@section('content')
    @php($blocks = $theme->sections())
    @if ($blocks)
        @include($theme->view('partials.blocks'), ['blocks' => $blocks])
    @else
        <div class="wrap section" style="text-align:center;color:var(--ink-muted)">{{ __('This store is getting ready. Check back soon.') }}</div>
    @endif
@endsection
