{{-- Slideshow: up to 3 slides, each with its own phone image; swipe on phones, dots and optional autoplay --}}
@php
    $slides = collect([1, 2, 3])->map(fn ($n) => [
        'img' => $theme->image($s["slide{$n}_image"]), 'mobile' => $theme->image($s["slide{$n}_mobile_image"]),
        'heading' => $s["slide{$n}_heading"], 'text' => $s["slide{$n}_text"], 'button' => $s["slide{$n}_button"], 'link' => $s["slide{$n}_link"],
    ])->filter(fn ($x) => $x['img'] || $x['heading'])->values();
@endphp
@if ($slides->isNotEmpty())
<section class="slideshow h-{{ $s['height'] }}" data-section-id="{{ $section['id'] }}" data-interval="{{ $s['autoplay'] && $slides->count() > 1 ? (int) $s['interval'] : 0 }}" aria-roledescription="carousel">
    <div class="ss-track">
        @foreach ($slides as $i => $sl)
            <div class="ss-slide {{ $sl['img'] ? 'has-img' : '' }}" role="group" aria-roledescription="slide" aria-label="{{ $i + 1 }} / {{ $slides->count() }}">
                @if ($sl['img'])
                    <picture>
                        @if ($sl['mobile'])<source media="(max-width: 720px)" srcset="{{ $sl['mobile'] }}">@endif
                        <img src="{{ $sl['img'] }}" alt="" @if ($i > 0) loading="lazy" @endif>
                    </picture>
                    <div class="ss-shade" style="opacity: {{ (int) $s['overlay'] / 100 }}"></div>
                @endif
                <div class="ss-copy">
                    @if ($sl['heading'])<h2 class="display">{{ $sl['heading'] }}</h2>@endif
                    @if ($sl['text'])<p>{{ $sl['text'] }}</p>@endif
                    @if ($sl['button'] && $sl['link'])<a class="btn" href="{{ $sl['link'] }}">{{ $sl['button'] }}</a>@endif
                </div>
            </div>
        @endforeach
    </div>
    @if ($slides->count() > 1)
        <div class="ss-dots">
            @foreach ($slides as $i => $sl)
                <button type="button" aria-label="{{ __('Go to slide :n', ['n' => $i + 1]) }}" @if ($i === 0) aria-current="true" @endif></button>
            @endforeach
        </div>
    @endif
</section>
@once
@push('head')
<style>
    .slideshow { position: relative; }
    .ss-track { display: flex; overflow-x: auto; scroll-snap-type: x mandatory; scrollbar-width: none; }
    .ss-track::-webkit-scrollbar { display: none; }
    .ss-slide { position: relative; flex: 0 0 100%; scroll-snap-align: start; display: flex; align-items: center; justify-content: center; background: var(--rose-gold-50); overflow: hidden; }
    .slideshow.h-small .ss-slide { min-height: 320px; } .slideshow.h-medium .ss-slide { min-height: 460px; } .slideshow.h-large .ss-slide { min-height: 620px; }
    .ss-slide picture, .ss-slide img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
    .ss-shade { position: absolute; inset: 0; background: #000; }
    .ss-copy { position: relative; text-align: center; padding: 40px 24px; max-width: 720px; }
    .ss-copy h2 { font-size: clamp(30px, 5vw, 56px); margin: 0 0 12px; }
    .ss-copy p { margin: 0 0 24px; font-size: 17px; }
    .ss-slide.has-img .ss-copy { color: #fff; }
    .ss-dots { position: absolute; inset-inline: 0; bottom: 14px; display: flex; justify-content: center; gap: 8px; }
    .ss-dots button { width: 9px; height: 9px; border-radius: 50%; border: 0; padding: 0; background: rgba(255,255,255,.55); box-shadow: 0 0 0 1px rgba(0,0,0,.15); cursor: pointer; }
    .ss-dots button[aria-current="true"] { background: #fff; width: 22px; border-radius: 6px; }
    @media (max-width: 720px) { .slideshow.h-large .ss-slide, .slideshow.h-medium .ss-slide { min-height: 420px; } }
</style>
@endpush
@push('scripts')
<script>
document.querySelectorAll('.slideshow').forEach(function (box) {
    var track = box.querySelector('.ss-track'), slides = track.children, dots = box.querySelectorAll('.ss-dots button'), current = 0, timer;
    function go(i) { current = (i + slides.length) % slides.length; track.scrollBy({ left: slides[current].getBoundingClientRect().left - track.getBoundingClientRect().left, behavior: 'smooth' }); }   // rect-based, so it works right to left too
    var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) { if (e.isIntersecting && e.intersectionRatio > .6) { current = [].indexOf.call(slides, e.target); dots.forEach(function (d, j) { d.toggleAttribute('aria-current', j === current); }); } });
    }, { root: track, threshold: [.6] });
    [].forEach.call(slides, function (s) { io.observe(s); });
    dots.forEach(function (d, j) { d.addEventListener('click', function () { go(j); }); });
    var every = +box.dataset.interval;
    if (every && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
        var start = function () { timer = setInterval(function () { go(current + 1); }, every * 1000); };
        box.addEventListener('mouseenter', function () { clearInterval(timer); });
        box.addEventListener('mouseleave', start);
        start();
    }
});
</script>
@endpush
@endonce
@endif
