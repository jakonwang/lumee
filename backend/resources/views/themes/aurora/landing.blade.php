@extends($theme->view('layout'))

{{-- Campaign landing page (page layout "blocks"): blocks designed in the theme editor › 活动页 --}}
@section('title', ($page->seo['title'] ?? null) ?: $page->title.' – '.$store->name)
@section('description', ($page->seo['description'] ?? null) ?: $page->title)

@section('content')
    <h1 class="sr-only">{{ $page->title }}</h1>
    @include($theme->view('partials.blocks'), ['blocks' => $blocks])
@endsection

@push('head')
<style>.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }</style>
@endpush
