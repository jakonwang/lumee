{{-- Customer photos from published reviews (masonry), each linking to the product --}}
@php
    $photos = app(\App\Themes\SectionData::class)->ugc((int) $s['limit'], (int) $s['min_rating']);
@endphp
@if ($photos)
<section class="section" data-section-id="{{ $section['id'] }}">
    <div class="wrap">
        @if ($s['heading'])<div class="section-head"><h2 class="display">{{ $s['heading'] }}</h2></div>@endif
        <div class="ugc">
            @foreach ($photos as $p)
                <a class="ugc-item" href="{{ $p['product'] ? route('store.product', $p['product']->handle, false) : '#' }}">
                    <img src="{{ $p['url'] }}" alt="{{ __('Photo from :name', ['name' => $p['author']]) }}" loading="lazy">
                    <span class="ugc-meta"><span aria-hidden="true">{{ str_repeat('★', $p['rating']) }}</span> {{ $p['author'] }}@if ($p['product']) · {{ $p['product']->title }}@endif</span>
                </a>
            @endforeach
        </div>
    </div>
</section>
@once
@push('head')
<style>
    .ugc { columns: 4 220px; column-gap: 14px; }
    .ugc-item { position: relative; display: block; margin-bottom: 14px; break-inside: avoid; border-radius: var(--radius-card, 12px); overflow: hidden; }
    .ugc-item img { display: block; width: 100%; height: auto; }
    .ugc-meta { position: absolute; inset-inline: 0; bottom: 0; padding: 22px 12px 10px; font-size: 12px; color: #fff; background: linear-gradient(transparent, rgba(0,0,0,.55)); opacity: 0; transition: opacity .2s; }
    .ugc-item:hover .ugc-meta, .ugc-item:focus .ugc-meta { opacity: 1; }
    @media (max-width: 720px) { .ugc { columns: 2; column-gap: 10px; } .ugc-meta { opacity: 1; } }
</style>
@endpush
@endonce
@endif
