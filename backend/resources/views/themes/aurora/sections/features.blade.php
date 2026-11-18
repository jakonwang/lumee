@php($gems = ['var(--gem-lavender)', 'var(--gem-blush)', 'var(--gem-aqua)'])
<section class="section features-section" data-section-id="{{ $section['id'] }}">
    <div class="wrap">
        @if ($s['heading'])
            <div class="section-head"><h2 class="display">{{ $s['heading'] }}</h2></div>
        @endif
        <div class="features">
            @foreach ([1, 2, 3] as $i)
                @if ($s["item{$i}_title"] || $s["item{$i}_text"])
                    <div class="feature">
                        <span class="dot" style="background: {{ $gems[$i - 1] }}"></span>
                        <h3>{{ $s["item{$i}_title"] }}</h3>
                        <p>{{ $s["item{$i}_text"] }}</p>
                    </div>
                @endif
            @endforeach
        </div>
    </div>
</section>

@once
@push('head')
<style>
    .features-section { background: var(--rose-gold-50); border-block: 1px solid var(--border); padding-block: 56px; }
    .features { display: grid; grid-template-columns: repeat(3, 1fr); gap: 32px; text-align: center; }
    .feature .dot { display: inline-block; width: 12px; height: 12px; border-radius: 50%; }
    .feature h3 { font-family: var(--font-display); font-weight: 600; font-size: 22px; margin: 12px 0 6px; }
    .feature p { color: var(--ink-muted); font-size: 14px; line-height: 1.6; margin: 0; }
    @media (max-width: 720px) { .features { grid-template-columns: 1fr; gap: 28px; } }
</style>
@endpush
@endonce
