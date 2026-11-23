@php($img = $theme->image($s['image']))
<section class="section" data-section-id="{{ $section['id'] }}">
    <div class="wrap image-text {{ $s['image_position'] === 'right' ? 'reverse' : '' }}">
        <div class="it-media">
            @if ($img)
                <img {{ \App\Media\Img::attrs($img, 'half') }} alt="" loading="lazy">
            @else
                <div class="it-placeholder"><img src="{{ $theme->placeholderUrl() }}" alt=""></div>
            @endif
        </div>
        <div class="it-body">
            @if ($s['eyebrow'])<div class="eyebrow">{{ $s['eyebrow'] }}</div>@endif
            @if ($s['heading'])<h2 class="display">{{ $s['heading'] }}</h2>@endif
            @if ($s['text'])<div class="prose">{{ $s['text'] }}</div>@endif
            @if ($s['button_label'] && $s['button_link'])
                <a class="btn" href="{{ $s['button_link'] }}" style="margin-top: 28px">{{ $s['button_label'] }}</a>
            @endif
        </div>
    </div>
</section>

@once
@push('head')
<style>
    .image-text { display: grid; grid-template-columns: 1fr 1fr; gap: 64px; align-items: center; }
    .image-text.reverse .it-media { order: 2; }
    .it-media img, .it-placeholder { width: 100%; aspect-ratio: 4 / 5; object-fit: cover; border-radius: var(--radius-card); display: block; }
    .it-placeholder { background: var(--surface-muted); display: flex; align-items: center; justify-content: center; }
    .it-placeholder img { width: 35%; aspect-ratio: auto; opacity: .45; }
    .it-body h2 { font-size: clamp(28px, 4vw, 40px); margin: 10px 0 18px; line-height: 1.15; }
    @media (max-width: 860px) {
        .image-text { grid-template-columns: 1fr; gap: 28px; }
        .image-text.reverse .it-media { order: 0; }
    }
</style>
@endpush
@endonce
