{{-- Products come from the chosen collection, else the newest products (App\Themes\SectionData) --}}
@php
    $items = app(\App\Themes\SectionData::class)->products($s['collection'] ? 'collection' : 'newest', $s['collection'], (int) $s['limit']);
@endphp
@php
    $source = $s['collection'] ? \App\Models\Collection::published()->find($s['collection']) : null;
@endphp
<section class="section" id="shop" data-section-id="{{ $section['id'] }}">
    <div class="wrap">
        @if ($s['eyebrow'] || $s['heading'])
            <div class="section-head">
                @if ($s['eyebrow'])<div class="eyebrow">{{ $s['eyebrow'] }}</div>@endif
                @if ($s['heading'])<h2 class="display">{{ $s['heading'] }}</h2>@endif
            </div>
        @endif

        @if ($items->isEmpty())
            <div class="empty">{{ __('Products are coming soon.') }}</div>
        @else
            @include($theme->view('partials.product-grid'), ['products' => $items, 'columns' => $s['columns']])
            <div style="text-align:center;margin-top:40px">
                <a class="btn btn-ghost" href="{{ $source ? '/collections/'.$source->handle : '/collections/all' }}">{{ __('View all') }}</a>
            </div>
        @endif
    </div>
</section>
