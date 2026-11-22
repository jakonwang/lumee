{{-- Shop the look: a scene photo with up to 3 tappable product points (positions are on the photo, so they don't mirror right to left) --}}
@php
    $img = $theme->image($s['image']);
    $products = app(\App\Themes\SectionData::class)->productsById([$s['point1_product'], $s['point2_product'], $s['point3_product']]);
    $points = collect([1, 2, 3])->map(fn ($n) => ['product' => $products->get($s["point{$n}_product"]), 'x' => (int) $s["point{$n}_x"], 'y' => (int) $s["point{$n}_y"]])
        ->filter(fn ($p) => $p['product'])->values();
@endphp
@if ($img)
<section class="section" data-section-id="{{ $section['id'] }}">
    <div class="wrap">
        @if ($s['heading'])<div class="section-head"><h2 class="display">{{ $s['heading'] }}</h2></div>@endif
        <div class="hotspots">
            <img src="{{ $img }}" alt="" loading="lazy">
            @foreach ($points as $i => $p)
                <div class="hs-point" style="left: {{ $p['x'] }}%; top: {{ $p['y'] }}%">
                    <button type="button" class="hs-dot" aria-expanded="false" aria-controls="{{ $section['id'] }}-hs{{ $i }}" aria-label="{{ $p['product']->title }}">+</button>
                    <a class="hs-card {{ $p['x'] > 60 ? 'to-left' : '' }} {{ $p['y'] > 65 ? 'up' : '' }}" id="{{ $section['id'] }}-hs{{ $i }}" href="{{ route('store.product', $p['product']->handle, false) }}" hidden>
                        <img src="{{ $p['product']->featured_image ?: $theme->placeholderUrl() }}" alt="" loading="lazy">
                        <span><strong>{{ $p['product']->title }}</strong>{{ $px->display($p['product']->variants->min('price')) }}</span>
                    </a>
                </div>
            @endforeach
        </div>
    </div>
</section>
@once
@push('head')
<style>
    .hotspots { position: relative; border-radius: var(--radius-card, 12px); overflow: visible; }
    .hotspots > img { display: block; width: 100%; height: auto; border-radius: var(--radius-card, 12px); }
    .hs-point { position: absolute; transform: translate(-50%, -50%); direction: ltr; }
    .hs-dot { width: 30px; height: 30px; border-radius: 50%; border: 2px solid #fff; background: rgba(0,0,0,.55); color: #fff; font-size: 18px; line-height: 1; cursor: pointer; box-shadow: 0 0 0 6px rgba(255,255,255,.3); }
    .hs-card { position: absolute; top: 38px; left: -12px; z-index: 2; display: flex; gap: 10px; align-items: center; width: 240px; padding: 8px; border-radius: 10px; background: var(--surface); color: var(--ink); box-shadow: 0 10px 30px rgba(0,0,0,.18); font-size: 13px; }
    .hs-card.to-left { left: auto; right: -12px; }
    .hs-card.up { top: auto; bottom: 38px; }
    .hs-card img { width: 56px; height: 56px; object-fit: cover; border-radius: 8px; }
    .hs-card span { display: grid; gap: 2px; }
    [dir="rtl"] .hs-card span { direction: rtl; text-align: right; }
</style>
@endpush
@push('scripts')
<script>
document.addEventListener('click', function (e) {
    var dot = e.target.closest('.hs-dot');
    document.querySelectorAll('.hs-dot[aria-expanded="true"]').forEach(function (d) {
        if (d !== dot) { d.setAttribute('aria-expanded', 'false'); document.getElementById(d.getAttribute('aria-controls')).hidden = true; }
    });
    if (!dot) return;
    var open = dot.getAttribute('aria-expanded') !== 'true';
    dot.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.getElementById(dot.getAttribute('aria-controls')).hidden = !open;
});
</script>
@endpush
@endonce
@endif
