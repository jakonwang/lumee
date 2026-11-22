{{-- Product page › delivery estimate: processing + transit business days from today, in the shopper's language --}}
@php
    [$from, $to] = \App\Themes\SectionData::deliveryWindow((int) $s['processing'], (int) $s['transit_min'], (int) $s['transit_max']);
    $fmt = fn ($d) => $d->locale(app()->getLocale())->isoFormat('ll');
@endphp
<div class="wrap eta" data-section-id="{{ $section['id'] }}">
    <span class="eta-icon" aria-hidden="true">🚚</span>
    <div>
        <strong>{{ __('Order today — arrives between :from and :to', ['from' => $fmt($from), 'to' => $fmt($to)]) }}</strong>
        @if ($s['note'])<div class="eta-note">{{ $s['note'] }}</div>@endif
    </div>
</div>
@once
@push('head')
<style>
    .eta { display: flex; gap: 12px; align-items: center; margin-block: 8px 24px; padding-block: 14px; border-block: 1px solid var(--border); font-size: 14px; }
    .eta-icon { font-size: 22px; }
    .eta-note { color: var(--ink-muted); font-size: 13px; margin-top: 2px; }
</style>
@endpush
@endonce
