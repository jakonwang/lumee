@php($quotes = collect([1, 2, 3])->map(fn ($i) => ['quote' => $s["quote$i"] ?? '', 'author' => $s["author$i"] ?? ''])->filter(fn ($x) => $x['quote'] !== ''))
<section class="section testimonials-section" data-section-id="{{ $section['id'] }}">
    <div class="wrap">
        @if ($s['heading'])
            <div class="section-head"><h2 class="display">{{ $s['heading'] }}</h2></div>
        @endif
        <div class="testimonials">
            @foreach ($quotes as $q)
                <figure class="testimonial">
                    <div class="stars" aria-hidden="true">★★★★★</div>
                    <blockquote>{{ $q['quote'] }}</blockquote>
                    @if ($q['author'])<figcaption>— {{ $q['author'] }}</figcaption>@endif
                </figure>
            @endforeach
        </div>
    </div>
</section>

@once
@push('head')
<style>
    .testimonials-section { background: var(--rose-gold-50); }
    .testimonials { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 24px; }
    .testimonial { margin: 0; padding: 28px; background: var(--ivory, #fff); border: 1px solid var(--border); border-radius: var(--radius-card, 12px); }
    .testimonial .stars { color: var(--rose-gold-500); letter-spacing: 2px; font-size: 14px; }
    .testimonial blockquote { margin: 12px 0 16px; font-size: 16px; line-height: 1.7; }
    .testimonial figcaption { color: var(--ink-muted); font-size: 14px; }
</style>
@endpush
@endonce
