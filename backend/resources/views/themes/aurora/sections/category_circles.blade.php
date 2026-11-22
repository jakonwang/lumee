{{-- Round (or rounded-square) collection shortcuts; scrolls sideways on phones --}}
@php
    $list = app(\App\Themes\SectionData::class)->collections(array_map(fn ($n) => $s["collection_$n"], range(1, 8)), 8);
@endphp
@if ($list->isNotEmpty())
<section class="section circles-section" data-section-id="{{ $section['id'] }}">
    <div class="wrap">
        @if ($s['heading'])<div class="section-head"><h2 class="display">{{ $s['heading'] }}</h2></div>@endif
        <div class="circles shape-{{ $s['shape'] }}">
            @foreach ($list as $c)
                <a class="circle" href="/collections/{{ $c->handle }}">
                    <span class="circle-img">
                        @if ($url = $c->imageUrl())<img src="{{ $url }}" alt="" loading="lazy">@else<img class="ph" src="{{ $theme->placeholderUrl() }}" alt="">@endif
                    </span>
                    <span class="circle-name">{{ $c->title }}</span>
                </a>
            @endforeach
        </div>
    </div>
</section>
@once
@push('head')
<style>
    .circles-section { padding-block: 32px; }
    .circles { display: flex; gap: 20px; justify-content: center; flex-wrap: wrap; }
    .circle { display: grid; justify-items: center; gap: 8px; width: 108px; text-align: center; font-size: 13px; }
    .circle-img { width: 96px; height: 96px; border-radius: 50%; overflow: hidden; background: var(--surface-muted); border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; }
    .shape-rounded .circle-img { border-radius: var(--radius-card, 12px); }
    .circle-img img { width: 100%; height: 100%; object-fit: cover; }
    .circle-img img.ph { width: 50%; height: auto; opacity: .5; }
    .circle:hover .circle-img { border-color: var(--rose-gold-500); }
    @media (max-width: 720px) {
        .circles { flex-wrap: nowrap; justify-content: flex-start; overflow-x: auto; gap: 14px; padding-bottom: 4px; scrollbar-width: none; }
        .circle { flex: 0 0 76px; width: 76px; font-size: 12px; }
        .circle-img { width: 68px; height: 68px; }
    }
</style>
@endpush
@endonce
@endif
