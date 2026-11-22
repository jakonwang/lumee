{{-- Coupons: codes that work right now (checked against the discounts), tap to copy --}}
@php
    $coupons = app(\App\Themes\SectionData::class)->coupons($s);
@endphp
@if ($coupons)
<section class="coupons-section" data-section-id="{{ $section['id'] }}">
    <div class="wrap">
        @if ($s['heading'])<h2 class="display coupons-title">{{ $s['heading'] }}</h2>@endif
        <div class="coupons">
            @foreach ($coupons as $c)
                <div class="coupon">
                    <div class="coupon-info"><strong>{{ $c['label'] }}</strong><code>{{ $c['code'] }}</code></div>
                    <button type="button" class="coupon-copy" data-code="{{ $c['code'] }}" data-done="{{ __('Copied') }}">{{ __('Copy') }}</button>
                </div>
            @endforeach
        </div>
    </div>
</section>
@once
@push('head')
<style>
    .coupons-section { padding-block: 28px; }
    .coupons-title { text-align: center; font-size: clamp(20px, 2.6vw, 26px); margin: 0 0 16px; }
    .coupons { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
    .coupon { display: flex; align-items: stretch; min-width: 240px; border: 1.5px dashed var(--rose-gold-500); border-radius: var(--radius-card, 12px); background: var(--rose-gold-50); overflow: hidden; }
    .coupon-info { flex: 1; display: grid; gap: 4px; padding: 12px 16px; }
    .coupon-info strong { font-size: 15px; color: var(--rose-gold-700); }
    .coupon-info code { font-size: 13px; letter-spacing: .08em; color: var(--ink); }
    .coupon-copy { border: 0; border-inline-start: 1.5px dashed var(--rose-gold-500); background: var(--rose-gold-500); color: var(--on-primary); padding: 0 16px; font-weight: 600; cursor: pointer; }
    @media (max-width: 720px) { .coupons { flex-wrap: nowrap; overflow-x: auto; justify-content: flex-start; } .coupon { flex: 0 0 82%; min-width: 0; } }
</style>
@endpush
@push('scripts')
<script>
document.addEventListener('click', function (e) {
    var b = e.target.closest('.coupon-copy');
    if (!b) return;
    var done = function () { var t = b.textContent; b.textContent = b.dataset.done; setTimeout(function () { b.textContent = t; }, 1600); };
    (navigator.clipboard ? navigator.clipboard.writeText(b.dataset.code) : Promise.reject()).then(done, done);
});
</script>
@endpush
@endonce
@endif
