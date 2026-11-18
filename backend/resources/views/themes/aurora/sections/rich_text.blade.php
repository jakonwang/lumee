<section class="section" data-section-id="{{ $section['id'] }}">
    <div class="wrap rich-text" style="text-align: {{ $s['align'] === 'left' ? 'left' : 'center' }}">
        @if ($s['eyebrow'])<div class="eyebrow">{{ $s['eyebrow'] }}</div>@endif
        @if ($s['heading'])<h2 class="display">{{ $s['heading'] }}</h2>@endif
        @if ($s['text'])<div class="prose">{{ $s['text'] }}</div>@endif
    </div>
</section>

@once
@push('head')
<style>
    .rich-text { max-width: 760px; }
    .rich-text h2 { font-size: clamp(28px, 4vw, 40px); margin: 10px 0 18px; line-height: 1.15; }
</style>
@endpush
@endonce
