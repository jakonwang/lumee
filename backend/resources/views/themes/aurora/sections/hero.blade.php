@php($img = $theme->image($s['image']))
@php($heights = ['small' => '360px', 'medium' => '520px', 'large' => '720px'])
<section class="hero {{ $img ? 'has-img' : '' }}" data-section-id="{{ $section['id'] }}"
         style="--hero-h: {{ $heights[$s['height']] ?? '520px' }}; --overlay: {{ $s['overlay'] / 100 }}">
    @if ($img)
        <img class="hero-bg" src="{{ $img }}" alt="" fetchpriority="high">
    @endif
    <div class="wrap hero-inner">
        @if ($s['eyebrow'])<div class="eyebrow">{{ $s['eyebrow'] }}</div>@endif
        <h1 class="display">{{ $s['heading'] ?: $store->name }}</h1>
        @if ($s['text'])<p>{{ $s['text'] }}</p>@endif
        @if ($s['button_label'])
            <a class="btn" href="{{ $s['button_link'] ?: '#shop' }}">{{ $s['button_label'] }}</a>
        @endif
    </div>
</section>

@once
@push('head')
<style>
    .hero { position: relative; overflow: hidden; min-height: var(--hero-h); display: flex; align-items: center; text-align: center; }
    .hero:not(.has-img)::before { content: ''; position: absolute; inset: -40% 10%; background: var(--gradient-gem); filter: blur(120px); opacity: .28; }
    .hero-bg { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
    .hero.has-img::after { content: ''; position: absolute; inset: 0; background: rgba(0, 0, 0, var(--overlay)); }
    .hero-inner { position: relative; z-index: 1; width: 100%; padding-block: 64px; }
    .hero h1 { font-size: clamp(38px, 7vw, 76px); margin: 16px 0 12px; letter-spacing: .04em; line-height: 1.1; }
    .hero p { color: var(--ink-muted); max-width: 520px; margin: 0 auto 36px; line-height: 1.7; white-space: pre-line; }
    .hero.has-img .hero-inner, .hero.has-img .eyebrow, .hero.has-img p { color: #fff; }
    .hero.has-img h1 { color: #fff; text-shadow: 0 2px 24px rgba(0,0,0,.25); }
    @media (max-width: 720px) { .hero { min-height: min(var(--hero-h), 520px); } }
</style>
@endpush
@endonce
