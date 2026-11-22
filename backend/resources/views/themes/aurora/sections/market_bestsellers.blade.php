{{-- Best sellers in this market (its own orders, recent days), topped up with new products while sales are few --}}
@php
    $items = app(\App\Themes\SectionData::class)->bestsellers((int) $s['days'], (int) $s['limit']);
@endphp
@if ($items->isNotEmpty())
<section class="section" data-section-id="{{ $section['id'] }}">
    <div class="wrap">
        @if ($s['eyebrow'] || $s['heading'])
            <div class="section-head">
                @if ($s['eyebrow'])<div class="eyebrow">{{ $s['eyebrow'] }}</div>@endif
                @if ($s['heading'])<h2 class="display">{{ $s['heading'] }}</h2>@endif
            </div>
        @endif
        @include($theme->view('partials.product-grid'), ['products' => $items, 'columns' => (int) $s['columns'], 'mobileColumns' => (int) $s['mobile_columns']])
    </div>
</section>
@endif
