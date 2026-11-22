{{-- Marquee: a line of short messages scrolling across (stops for people who prefer less motion) --}}
@php
    $lines = array_values(array_filter(array_map('trim', explode("\n", (string) $s['text']))));
@endphp
@if ($lines)
<div class="marquee m-{{ $s['style'] }} speed-{{ $s['speed'] }}" data-section-id="{{ $section['id'] }}" role="marquee" aria-label="{{ implode(' · ', $lines) }}">
    <div class="marquee-track" aria-hidden="true">
        @for ($copy = 0; $copy < 2; $copy++)
            <span class="marquee-group">@foreach ($lines as $l)<span>{{ $l }}</span><b>✦</b>@endforeach</span>
        @endfor
    </div>
</div>
@once
@push('head')
<style>
    .marquee { overflow: hidden; padding: 11px 0; font-size: 13px; letter-spacing: .04em; }
    .marquee.m-dark { background: var(--ink); color: var(--ivory); }
    .marquee.m-primary { background: var(--rose-gold-500); color: var(--on-primary); }
    .marquee.m-accent { background: var(--gem-blush); color: var(--on-accent); }
    .marquee-track { display: flex; width: max-content; animation: marquee 30s linear infinite; }
    .marquee.speed-slow .marquee-track { animation-duration: 48s; } .marquee.speed-fast .marquee-track { animation-duration: 18s; }
    .marquee-group { display: flex; gap: 28px; padding-inline-end: 28px; white-space: nowrap; }
    .marquee-group b { font-weight: 400; opacity: .6; }
    @keyframes marquee { to { transform: translateX(-50%); } }
    [dir="rtl"] .marquee-track { animation-name: marquee-rtl; }
    @keyframes marquee-rtl { to { transform: translateX(50%); } }
    @media (prefers-reduced-motion: reduce) { .marquee-track { animation: none; } }
</style>
@endpush
@endonce
@endif
