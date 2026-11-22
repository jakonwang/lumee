<section class="section" data-section-id="{{ $section['id'] }}" id="newsletter-{{ $section['id'] }}">
    <div class="wrap newsletter">
        @if ($s['heading'])<h2 class="display">{{ $s['heading'] }}</h2>@endif
        @if ($s['text'])<p class="prose">{{ $s['text'] }}</p>@endif
        @if (session('newsletter_status'))
            <p class="nl-done" role="status">{{ session('newsletter_status') }}</p>
        @else
            <form method="post" action="/newsletter#newsletter-{{ $section['id'] }}" class="nl-inline">
                @csrf
                <input type="hidden" name="source" value="form">
                <input type="text" name="website" tabindex="-1" autocomplete="off" class="nl-hp" aria-hidden="true">
                <input class="field" type="email" name="email" required placeholder="{{ __('Email') }}" autocomplete="email" aria-label="{{ __('Email') }}" value="{{ old('email') }}">
                <button class="btn" type="submit">{{ $s['button_label'] ?: __('Subscribe') }}</button>
            </form>
            @error('email')<p class="nl-error">{{ $message }}</p>@enderror
        @endif
    </div>
</section>

@once
@push('head')
<style>
    .newsletter { max-width: 620px; text-align: center; }
    .newsletter h2 { font-size: clamp(26px, 4vw, 36px); margin: 0 0 12px; }
    .newsletter .prose { color: var(--ink-muted); margin: 0 0 22px; }
    .nl-inline { display: flex; gap: 10px; max-width: 480px; margin: 0 auto; }
    .nl-inline .field { flex: 1; }
    .nl-done { font-size: 16px; color: var(--rose-gold-700); }
    .nl-error { color: #B42318; font-size: 13px; margin: 8px 0 0; }
    .nl-hp { position: absolute; inset-inline-start: -9999px; width: 1px; height: 1px; opacity: 0; }
    @media (max-width: 560px) { .nl-inline { flex-direction: column; } }
</style>
@endpush
@endonce
