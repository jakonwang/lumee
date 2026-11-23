{{-- Cart › recommendations to add to the bag (App\Catalog\Upsells) --}}
@php($m = fn ($v) => $px->display($v))
<div class="wrap">
@if ($cart && $cart->items->isNotEmpty() && ($suggestions ?? collect())->isNotEmpty() && ! app(\App\B2B\B2B::class)->hidePrices())
    <section class="cart-upsell" data-section-id="{{ $section['id'] }}" aria-labelledby="upsell-title">
        <h2 id="upsell-title">{{ $s['heading'] === 'You might also like' ? __('You might also like') : $s['heading'] }}</h2>
        <div class="upsell-list">
            @foreach ($suggestions->take((int) $s['limit']) as $sp)
                <div class="upsell">
                    <a href="{{ route('store.product', $sp->handle, false) }}"><img {{ \App\Media\Img::attrs($sp->featured_image ?: $theme->placeholderUrl(), 'thumb') }} alt="" loading="lazy"></a>
                    <a class="upsell-name" href="{{ route('store.product', $sp->handle, false) }}">{{ $sp->title }}</a>
                    <span class="upsell-price">{{ $px->display($sp->variants->min('price')) }}</span>
                    @if ($qv = \App\Catalog\Upsells::quickVariant($sp))
                        <form method="post" action="/cart/add">@csrf<input type="hidden" name="variant_id" value="{{ $qv }}"><button class="btn btn-ghost btn-sm" type="submit">{{ __('Add') }}</button></form>
                    @else
                        <a class="btn btn-ghost btn-sm" href="{{ route('store.product', $sp->handle, false) }}">{{ __('Choose options') }}</a>
                    @endif
                </div>
            @endforeach
        </div>
    </section>
    @push('head')
    <style>
        .cart-upsell { margin-top: 40px; padding-top: 28px; border-top: 1px solid var(--border); }
        .cart-upsell h2 { font-size: 20px; margin: 0 0 16px; }
        .upsell-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 16px; }
        .upsell { display: grid; gap: 6px; align-content: start; font-size: 14px; }
        .upsell img { width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: var(--radius-card, 12px); display: block; }
        .upsell-name { color: inherit; text-decoration: none; }
        .upsell-price { color: var(--ink-muted); }
        .btn-sm { padding: 8px 12px; font-size: 13px; }
    </style>
    @endpush
@endif
</div>
