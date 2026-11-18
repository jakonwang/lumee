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
    <div class="wrap">
        <div class="coll-head">
            <div class="eyebrow">{{ __('Collection') }}</div>
            <h1 class="display">{{ $collection->title }}</h1>
            @if ($collection->description)<p>{{ $collection->description }}</p>@endif
        </div>

        <div class="toolbar">
            <span>{{ trans_choice(':count product|:count products', $products->total()) }}</span>
            <form method="get">
                <label for="sort">{{ __('Sort by') }}</label>
                <select id="sort" name="sort" onchange="this.form.submit()">
                    @foreach ($sorts as $key => $label)
                        <option value="{{ $key }}" @selected($sort === $key)>{{ __($label) }}</option>
                    @endforeach
                </select>
                <noscript><button type="submit">{{ __('Apply') }}</button></noscript>
            </form>
        </div>

        @if ($products->isEmpty())
            <div class="empty" style="text-align:center;color:var(--ink-muted);padding:64px 0">{{ __('No products in this collection yet.') }}</div>
        @else
            @include($theme->view('partials.product-grid'), ['products' => $products])
        @endif

        @if ($products->hasPages())
            <nav class="pager" aria-label="{{ __('Pagination') }}">
                @if ($products->onFirstPage())<span class="disabled">‹</span>@else<a href="{{ $products->previousPageUrl() }}" rel="prev">‹</a>@endif
                @foreach ($products->getUrlRange(max(1, $products->currentPage() - 2), min($products->lastPage(), $products->currentPage() + 2)) as $page => $url)
                    @if ($page === $products->currentPage())<span class="current" aria-current="page">{{ $page }}</span>@else<a href="{{ $url }}">{{ $page }}</a>@endif
                @endforeach
                @if ($products->hasMorePages())<a href="{{ $products->nextPageUrl() }}" rel="next">›</a>@else<span class="disabled">›</span>@endif
            </nav>
        @endif
    </div>
@endsection
