{{-- Collection page › the product grid with filters, sort and pages (always on the page) --}}
@php($filters ??= ['groups' => [], 'price' => null, 'active' => [], 'any' => false])
@php($showFilters = ($s['show_filters'] ?? true) && ($filters['groups'] || $filters['price'] || $filters['any']))
<div class="wrap coll-grid {{ $showFilters ? 'has-filters' : '' }}" data-section-id="{{ $section['id'] ?? 'grid' }}">
    <div class="toolbar">
        <span>{{ trans_choice(':count product|:count products', $products->total()) }}</span>
        @if ($s['show_sort'] ?? true)
        <form method="get" class="sort-form">
            @foreach (request()->query('f', []) as $k => $v)
                @foreach ((array) $v as $kk => $vv)
                    @if (is_array($vv))
                        @foreach ($vv as $vvv)<input type="hidden" name="f[{{ $k }}][{{ $kk }}][]" value="{{ $vvv }}">@endforeach
                    @elseif (is_int($kk))
                        <input type="hidden" name="f[{{ $k }}][]" value="{{ $vv }}">
                    @else
                        <input type="hidden" name="f[{{ $k }}]" value="{{ $vv }}">
                    @endif
                @endforeach
            @endforeach
            <label for="sort">{{ __('Sort by') }}</label>
            <select id="sort" name="sort" onchange="this.form.submit()">
                @foreach ($sorts as $key => $label)
                    <option value="{{ $key }}" @selected($sort === $key)>{{ __($label) }}</option>
                @endforeach
            </select>
            <noscript><button type="submit">{{ __('Apply') }}</button></noscript>
        </form>
        @endif
    </div>

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
                <div class="empty" style="text-align:center;color:var(--ink-muted);padding:64px 0">
                    @if ($filters['any'])
                        {{ __('No products match these filters.') }} <a href="{{ $clearFilters }}">{{ __('Clear all') }}</a>
                    @else
                        {{ __('No products in this collection yet.') }}
                    @endif
                </div>
            @else
                @include($theme->view('partials.product-grid'), ['products' => $products, 'columns' => (int) ($s['columns'] ?? 4) - ($showFilters && (int) ($s['columns'] ?? 4) > 3 ? 1 : 0), 'mobileColumns' => (int) ($s['mobile_columns'] ?? 2)])
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
