{{-- SEO landing page (App\Marketing\SeoPages): one market + keyword, its matching products, a short intro and FAQ --}}
@extends($theme->view('layout'))

@section('title', $page->title)
@section('description', $page->meta_description ?: $page->heading)
@section('single_site', '1')

@push('head')
<style>
    .seo-wrap { max-width: 1200px; margin: 0 auto; padding: 48px 16px 0; }
    .seo-head { max-width: 760px; margin: 0 auto 32px; text-align: center; }
    .seo-head h1 { font-size: clamp(30px, 4.5vw, 44px); margin: 8px 0 16px; }
    .seo-intro { line-height: 1.75; font-size: 16px; color: var(--ink-muted); }
    .seo-intro p { margin: 0 0 12px; }
    .seo-faq { max-width: 760px; margin: 56px auto 0; }
    .seo-faq h2 { font-family: var(--font-display); font-size: 26px; margin: 0 0 16px; font-weight: 600; }
    .seo-faq details { border-top: 1px solid var(--border); padding: 14px 0; }
    .seo-faq summary { cursor: pointer; font-weight: 600; }
    .seo-faq p { margin: 8px 0 0; line-height: 1.7; color: var(--ink-muted); }
</style>
@if ($page->faq)
<script type="application/ld+json">{!! json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn ($f) => ['@type' => 'Question', 'name' => $f['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']]], $page->faq)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
@endif
@endpush

@section('content')
    <div class="seo-wrap">
        <header class="seo-head">
            <div class="eyebrow">{{ $store->name }}</div>
            <h1 class="display">{{ $page->heading }}</h1>
            {{-- Sanitized when saved (SeoPages::clean): only p, ul, ol, li, strong, em, h2, h3, br, no attributes --}}
            @if ($page->intro)<div class="seo-intro">{!! $page->intro !!}</div>@endif
        </header>
        @include($theme->view('partials.product-grid'), ['products' => $products, 'columns' => 4, 'mobileColumns' => 2])
        @if ($page->faq)
            <section class="seo-faq">
                <h2>FAQ</h2>
                @foreach ($page->faq as $f)
                    <details><summary>{{ $f['q'] }}</summary><p>{{ $f['a'] }}</p></details>
                @endforeach
            </section>
        @endif
    </div>
@endsection
