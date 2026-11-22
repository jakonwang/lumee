{{-- Collection page › the product grid with sort and pages (always on the page) --}}
<div class="wrap coll-grid" data-section-id="{{ $section['id'] ?? 'grid' }}">
    <div class="toolbar">
        <span>{{ trans_choice(':count product|:count products', $products->total()) }}</span>
        @if ($s['show_sort'] ?? true)
        <form method="get">
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

    @if ($products->isEmpty())
        <div class="empty" style="text-align:center;color:var(--ink-muted);padding:64px 0">{{ __('No products in this collection yet.') }}</div>
    @else
        @include($theme->view('partials.product-grid'), ['products' => $products, 'columns' => (int) ($s['columns'] ?? 4), 'mobileColumns' => (int) ($s['mobile_columns'] ?? 2)])
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
