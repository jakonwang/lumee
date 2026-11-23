{{-- Collection page › title and description (optionally over the collection image) --}}
@php
    $bannerImg = $s['show_image'] && method_exists($collection, 'imageUrl') && $collection->exists ? $collection->imageUrl() : null;
@endphp
<div class="coll-banner align-{{ $s['align'] }} {{ $bannerImg ? 'has-img' : '' }}" data-section-id="{{ $section['id'] }}">
    @if ($bannerImg)<img {{ \App\Media\Img::attrs($bannerImg, 'hero') }} alt="" fetchpriority="high">@endif
    <div class="wrap coll-head">
        <div class="eyebrow">{{ __('Collection') }}</div>
        <h1 class="display">{{ $collection->title }}</h1>
        @if ($s['show_description'] && $collection->description)<p>{{ $collection->description }}</p>@endif
    </div>
</div>
@once
@push('head')
<style>
    .coll-banner { position: relative; }
    .coll-banner.align-left .coll-head { text-align: start; }
    .coll-banner.align-left .coll-head p { margin-inline: 0; }
    .coll-banner.has-img { min-height: 300px; display: flex; align-items: center; overflow: hidden; margin-bottom: 32px; }
    .coll-banner.has-img > img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
    .coll-banner.has-img::after { content: ''; position: absolute; inset: 0; background: rgba(0,0,0,.35); }
    .coll-banner.has-img .coll-head { position: relative; z-index: 1; color: #fff; width: 100%; }
    .coll-banner.has-img .coll-head p, .coll-banner.has-img .eyebrow { color: rgba(255,255,255,.88); }
</style>
@endpush
@endonce
