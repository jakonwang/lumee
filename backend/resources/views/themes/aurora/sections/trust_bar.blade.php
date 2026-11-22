{{-- Trust bar, filled in for the shopper's market: free-shipping threshold, returns, duties, payment methods --}}
@php
    $items = app(\App\Themes\SectionData::class)->trust($s);
@endphp
@if ($items)
<section class="trust-bar" data-section-id="{{ $section['id'] }}">
    <ul class="wrap">
        @foreach ($items as $item)
            <li><span aria-hidden="true">✓</span>{{ $item }}</li>
        @endforeach
    </ul>
</section>
@once
@push('head')
<style>
    .trust-bar { border-block: 1px solid var(--border); background: var(--surface); }
    .trust-bar ul { list-style: none; margin: 0 auto; padding-block: 14px; display: flex; justify-content: center; flex-wrap: wrap; gap: 10px 28px; font-size: 13px; color: var(--ink); }
    .trust-bar li { display: flex; gap: 8px; align-items: center; }
    .trust-bar li span { color: var(--rose-gold-500); font-weight: 700; }
    @media (max-width: 720px) { .trust-bar ul { justify-content: flex-start; } }
</style>
@endpush
@endonce
@endif
