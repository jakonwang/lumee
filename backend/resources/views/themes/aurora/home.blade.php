@extends($theme->view('layout'))

@section('title', $store->settings['seo']['title'] ?? $store->name)
@section('description', $store->settings['seo']['description'] ?? $store->name)
@if ($theme->logoUrl())
    @section('og_image', $theme->logoUrl())
@endif

@push('head')
@php
    $siteUrl = rtrim(url('/'), '/');
    $homeLd = ['@context' => 'https://schema.org', '@graph' => [
        array_filter(['@type' => 'Organization', 'name' => $store->name, 'url' => $siteUrl.'/', 'logo' => $theme->logoUrl()]),
        ['@type' => 'WebSite', 'name' => $store->name, 'url' => $siteUrl.'/',
            'potentialAction' => ['@type' => 'SearchAction', 'target' => $siteUrl.'/search?q={search_term_string}', 'query-input' => 'required name=search_term_string']],
    ]];
@endphp
<script type="application/ld+json">{!! json_encode($homeLd, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
@endpush

@section('content')
    @php($blocks = $theme->sections())
    {{-- Every page needs one main heading (search engines, screen readers); a page that doesn't open with the hero gets a hidden one --}}
    @if (($blocks[0]['type'] ?? null) !== 'hero')
        <h1 class="visually-hidden">{{ $store->settings['seo']['title'] ?? $store->name }}</h1>
    @endif
    @if ($blocks)
        @include($theme->view('partials.blocks'), ['blocks' => $blocks])
    @else
        <div class="wrap section" style="text-align:center;color:var(--ink-muted)">{{ __('This store is getting ready. Check back soon.') }}</div>
    @endif
@endsection
