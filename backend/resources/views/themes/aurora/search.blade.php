@extends($theme->view('layout'))

@section('title', ($q !== '' ? __('Search: :q', ['q' => $q]) : __('Search')).' – '.$store->name)
@section('noindex', '1')

@push('head')
<style>
    .search-head { text-align: center; padding: 56px 0 32px; }
    .search-head h1 { font-size: clamp(32px, 5vw, 48px); margin: 8px 0 24px; }
    .search-form { display: flex; gap: 10px; max-width: 560px; margin: 0 auto; }
    .search-form .field { flex: 1; }
    .search-form .btn { padding: 12px 26px; }
    .search-meta { text-align: center; color: var(--ink-muted); font-size: 14px; margin-bottom: 28px; }
    .pager { display: flex; justify-content: center; gap: 8px; margin-top: 48px; }
    .pager a, .pager span { min-width: 40px; padding: 8px 12px; text-align: center; border-radius: 999px; border: 1px solid var(--border); font-size: 14px; }
    .pager .current { background: var(--rose-gold-500); color: var(--on-primary); border-color: var(--rose-gold-500); }
    .pager .disabled { opacity: .4; }
    .no-results { text-align: center; color: var(--ink-muted); padding: 32px 0 16px; }
</style>
@endpush

@section('content')
    <div class="wrap">
        <div class="search-head">
            <div class="eyebrow">{{ __('Search') }}</div>
            <h1 class="display">{{ $q !== '' ? __('Results') : __('Search our store') }}</h1>
            <form class="search-form" method="get" action="/search" role="search">
                <label class="sr" for="q" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);">{{ __('Search products') }}</label>
                <input class="field" id="q" type="search" name="q" value="{{ $q }}" placeholder="{{ __('Search products') }}" maxlength="100" autofocus>
                <button class="btn" type="submit">{{ __('Search') }}</button>
            </form>
        </div>

        @if ($products !== null)
            @if ($products->total() === 0 && ! $filters['any'])
                <div class="no-results">
                    <p>{{ __('No products found for “:q”.', ['q' => $q]) }}</p>
                    <p><a href="/collections/all" style="color:var(--rose-gold-700);text-decoration:underline;">{{ __('Browse all products') }}</a></p>
                </div>
            @else
                <p class="search-meta">{{ trans_choice(':count result for “:q”|:count results for “:q”', $products->total(), ['q' => $q]) }}</p>
                @php($showFilters = $filters['groups'] || $filters['price'] || $filters['any'])
                <div class="coll-grid {{ $showFilters ? 'has-filters' : '' }}">
                    <div class="coll-body">
                        @if ($showFilters)
                            @include($theme->view('partials.filters'), ['filters' => $filters])
                        @endif
                        <div class="coll-main">
                            @if ($filters['active'])
                                <div class="f-active">
                                    @foreach ($filters['active'] as $chip)
                                        <a href="{{ $chip['url'] }}" class="f-chip" aria-label="{{ __('Remove') }} {{ $chip['label'] }}">{{ $chip['label'] }} <span aria-hidden="true">×</span></a>
                                    @endforeach
                                    <a href="{{ $clearFilters }}" class="f-clear">{{ __('Clear all') }}</a>
                                </div>
                            @endif
                            @if ($products->isEmpty())
                                <div class="no-results"><p>{{ __('No products match these filters.') }} <a href="{{ $clearFilters }}">{{ __('Clear all') }}</a></p></div>
                            @else
                                @include($theme->view('partials.product-grid'), ['products' => $products, 'columns' => $showFilters ? 3 : 4])
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
                    </div>
                </div>
            @endif
        @endif
    </div>
@endsection
