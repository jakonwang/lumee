{{-- Flash sale: countdown + products; hidden once the end time has passed (also in the browser, live) --}}
@php
    $sale = app(\App\Themes\SectionData::class)->flashSale($s);
@endphp
@if ($sale)
<section class="section flash-sale" data-section-id="{{ $section['id'] }}">
    <div class="wrap">
        <div class="fs-head">
            <h2 class="display">⚡ {{ $s['heading'] }}</h2>
            <div class="fs-timer" data-ends="{{ $sale['ends_at']->toIso8601String() }}" aria-live="off">
                <span class="fs-label">{{ __('Ends in') }}</span>
                <span class="fs-clock"><b data-u="d">00</b><i>:</i><b data-u="h">00</b><i>:</i><b data-u="m">00</b><i>:</i><b data-u="s">00</b></span>
            </div>
        </div>
        @include($theme->view('partials.product-grid'), ['products' => $sale['products'], 'columns' => 4, 'mobileColumns' => 2])
    </div>
</section>
@once
@push('head')
<style>
    .fs-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 24px; }
    .fs-head h2 { margin: 0; font-size: clamp(24px, 3.4vw, 34px); }
    .fs-timer { display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--ink-muted); }
    .fs-clock { display: flex; align-items: center; gap: 3px; direction: ltr; }
    .fs-clock b { min-width: 34px; padding: 6px 4px; text-align: center; border-radius: 6px; background: var(--ink); color: var(--ivory); font-variant-numeric: tabular-nums; font-size: 15px; }
    .fs-clock i { font-style: normal; color: var(--ink); font-weight: 700; }
</style>
@endpush
@push('scripts')
<script>
document.querySelectorAll('.fs-timer').forEach(function (t) {
    var ends = Date.parse(t.dataset.ends), box = t.closest('.flash-sale'), pad = function (n) { return String(n).padStart(2, '0'); };
    (function tick() {
        var left = Math.max(0, Math.floor((ends - Date.now()) / 1000));
        if (!left) { box.hidden = true; return; }
        var v = { d: Math.floor(left / 86400), h: Math.floor(left / 3600) % 24, m: Math.floor(left / 60) % 60, s: left % 60 };
        t.querySelectorAll('b').forEach(function (b) { b.textContent = pad(v[b.dataset.u]); });
        setTimeout(tick, 1000);
    })();
});
</script>
@endpush
@endonce
@endif
