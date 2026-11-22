{{-- Collection tiles. Expects $collections (Collection models). --}}
<div class="coll-grid cols-{{ min(4, max(2, $collections->count())) }}">
    @foreach ($collections as $c)
        @php($img = $c->imageUrl())
        <a class="coll-tile" href="/collections/{{ $c->handle }}">
            <div class="coll-img">
                @if ($img)<img src="{{ $img }}" alt="" loading="lazy">@else<img class="ph" src="{{ $theme->placeholderUrl() }}" alt="">@endif
            </div>
            <div class="coll-name">{{ $c->title }} <span aria-hidden="true">→</span></div>
        </a>
    @endforeach
</div>

@once
@push('head')
<style>
    .coll-grid { display: grid; gap: 20px; grid-template-columns: repeat(4, 1fr); }
    .coll-grid.cols-2 { grid-template-columns: repeat(2, 1fr); }
    .coll-grid.cols-3 { grid-template-columns: repeat(3, 1fr); }
    .coll-img { aspect-ratio: 1; border-radius: var(--radius-card); overflow: hidden; background: var(--surface-muted); display: flex; align-items: center; justify-content: center; }
    .coll-img img { width: 100%; height: 100%; object-fit: cover; transition: transform .6s ease; }
    .coll-img img.ph { width: 35%; height: auto; opacity: .45; }
    .coll-tile:hover .coll-img img { transform: scale(1.04); }
    .coll-name { font-family: var(--font-display); font-size: 22px; margin-top: 12px; }
    .coll-name span { color: var(--rose-gold-500); transition: margin .2s; }
    .coll-tile:hover .coll-name span { margin-inline-start: 6px; }
    @media (max-width: 760px) { .coll-grid, .coll-grid.cols-3, .coll-grid.cols-4 { grid-template-columns: repeat(2, 1fr); gap: 14px; } .coll-name { font-size: 18px; } }
</style>
@endpush
@endonce
