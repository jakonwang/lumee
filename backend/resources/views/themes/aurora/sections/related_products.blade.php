{{-- Product page › recommendations: same collections (prepared by the controller), this market's best sellers, or newest --}}
@php
    $limit = (int) $s['limit'];
    $items = match ($s['source']) {
        'bestsellers' => app(\App\Themes\SectionData::class)->bestsellers(30, $limit + 1)->where('id', '!=', $product->id)->take($limit),
        'newest' => app(\App\Themes\SectionData::class)->products('newest', null, $limit + 1)->where('id', '!=', $product->id)->take($limit),
        default => ($related ?? collect())->take($limit),
    };
    // The default heading is shown in the shopper's language; a heading the merchant wrote is shown as written
    $title = $s['heading'] === 'You may also like' ? __('You may also like') : $s['heading'];
@endphp
@if ($items->isNotEmpty())
    <section class="wrap related-products" data-section-id="{{ $section['id'] }}" aria-labelledby="{{ $section['id'] }}-title">
        @if ($title)<h2 id="{{ $section['id'] }}-title" class="display">{{ $title }}</h2>@endif
        @include($theme->view('partials.product-grid'), ['products' => $items->values(), 'columns' => (int) $s['columns'], 'mobileColumns' => (int) $s['mobile_columns']])
    </section>
    @once
    @push('head')
    <style>
        .related-products { padding-block: 56px 72px; border-top: 1px solid var(--border); }
        .related-products h2 { text-align: center; font-size: clamp(24px, 3vw, 32px); margin: 0 0 32px; }
    </style>
    @endpush
    @endonce
@endif
