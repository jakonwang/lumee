{{-- Product page › frequently bought together (App\Catalog\Upsells) --}}
@if (($together ?? collect())->isNotEmpty() && ! app(\App\B2B\B2B::class)->hidePrices())
    <section class="wrap fbt" data-section-id="{{ $section['id'] }}" aria-labelledby="fbt-title">
        <h2 id="fbt-title" class="display">{{ __('Frequently bought together') }}</h2>
        <form method="post" action="/cart/add-many" class="fbt-form">
            @csrf
            <div class="fbt-list">
                @foreach ($together as $tp)
                    @php
                        $quick = \App\Catalog\Upsells::quickVariant($tp);
                    @endphp
                    <div class="fbt-item">
                        <a href="{{ route('store.product', $tp->handle, false) }}" class="fbt-img">
                            <img {{ \App\Media\Img::attrs($tp->featured_image ?: $theme->placeholderUrl(), 'thumb') }} alt="" loading="lazy">
                        </a>
                        <div class="fbt-info">
                            <a href="{{ route('store.product', $tp->handle, false) }}">{{ $tp->title }}</a>
                            <span class="fbt-price">{{ $m($tp->variants->min('price')) }}</span>
                        </div>
                        @if ($quick)
                            <label class="fbt-pick"><input type="checkbox" name="variant_ids[]" value="{{ $quick }}" checked> {{ __('Add') }}</label>
                        @else
                            <a class="fbt-pick" href="{{ route('store.product', $tp->handle, false) }}">{{ __('Choose options') }}</a>
                        @endif
                    </div>
                @endforeach
            </div>
            @if ($together->contains(fn ($tp) => \App\Catalog\Upsells::quickVariant($tp)))
                <button class="btn btn-ghost" type="submit">{{ __('Add selected to bag') }}</button>
            @endif
        </form>
    </section>
    @push('head')
    <style>
        .fbt { padding-block: 40px; border-top: 1px solid var(--border); }
        .fbt h2 { font-size: clamp(22px, 2.6vw, 28px); margin: 0 0 20px; }
        .fbt-list { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; margin-bottom: 16px; }
        .fbt-item { display: flex; gap: 12px; align-items: center; border: 1px solid var(--border); border-radius: var(--radius-card, 12px); padding: 10px; }
        .fbt-img img { width: 64px; height: 64px; object-fit: cover; border-radius: 8px; display: block; }
        .fbt-info { flex: 1; min-width: 0; display: grid; gap: 2px; font-size: 14px; }
        .fbt-info a { color: inherit; text-decoration: none; }
        .fbt-price { color: var(--ink-muted); }
        .fbt-pick { font-size: 13px; white-space: nowrap; display: flex; gap: 4px; align-items: center; }
    </style>
    @endpush
@endif
