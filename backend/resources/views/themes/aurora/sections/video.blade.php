@php($embed = \App\Themes\VideoEmbed::url($s['video_url'] ?? ''))
<section class="section" data-section-id="{{ $section['id'] }}">
    <div class="{{ $s['width'] === 'full' ? 'video-full' : 'wrap video-narrow' }}">
        @if ($s['heading'])
            <div class="section-head"><h2 class="display">{{ $s['heading'] }}</h2></div>
        @endif
        <div class="video-frame">
            @if ($embed)
                <iframe src="{{ $embed }}" title="{{ $s['heading'] ?: 'Video' }}" loading="lazy"
                    allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowfullscreen
                    referrerpolicy="strict-origin-when-cross-origin"></iframe>
            @else
                <div class="video-empty">▶</div>
            @endif
        </div>
        @if ($s['text'])<p class="prose video-text">{{ $s['text'] }}</p>@endif
    </div>
</section>

@once
@push('head')
<style>
    .video-narrow { max-width: 980px; }
    .video-frame { position: relative; aspect-ratio: 16 / 9; background: var(--surface-muted); border-radius: var(--radius-card, 12px); overflow: hidden; }
    .video-full .video-frame { border-radius: 0; }
    .video-frame iframe { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; }
    .video-empty { position: absolute; inset: 0; display: grid; place-items: center; font-size: 40px; color: var(--rose-gold-300); }
    .video-text { text-align: center; max-width: 680px; margin: 20px auto 0; }
</style>
@endpush
@endonce
