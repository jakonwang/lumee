@extends($theme->view('layout'))

@section('title', __('Your bag').' – '.$store->name)

@push('head')
<style>
    .cart { padding-block: 56px; }
    .cart h1 { font-size: 40px; margin: 6px 0 32px; }
    .cart-grid { display: grid; grid-template-columns: 1fr 340px; gap: 48px; align-items: start; }
    .line { display: grid; grid-template-columns: 96px 1fr auto; gap: 20px; padding: 20px 0; border-bottom: 1px solid var(--border); align-items: center; }
    .line:first-child { border-top: 1px solid var(--border); }
    .thumb { width: 96px; aspect-ratio: 4/5; border-radius: 10px; background: var(--surface-muted); display: flex; align-items: center; justify-content: center; overflow: hidden; }
    .thumb img { width: 100%; height: 100%; object-fit: cover; }
    .thumb img.ph { width: 50%; height: auto; opacity: .5; }
    .line-title { font-weight: 500; }
    .line-meta { color: var(--ink-muted); font-size: 13px; margin-top: 4px; }
    .line-meta .was { color: var(--ink-muted); margin-left: 6px; font-size: 13px; }
    .qty-form { display: flex; align-items: center; gap: 8px; margin-top: 10px; }
    .qty-form .field { width: 70px; padding: 6px 10px; text-align: center; }
    .link-btn { background: none; border: none; padding: 0; color: var(--ink-muted); font-size: 12px; text-decoration: underline; cursor: pointer; }
    .line-total { text-align: right; }
    .summary { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-card); padding: 28px; box-shadow: var(--shadow-soft); }
    .summary-row { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 14px; }
    .summary-row.total { font-size: 18px; font-weight: 600; margin: 18px 0 22px; padding-top: 18px; border-top: 1px solid var(--border); }
    .note { color: var(--ink-muted); font-size: 12px; margin-top: 14px; text-align: center; }
    .discount-row { color: var(--rose-gold-700); }
    .code-tag { font-size: 11px; letter-spacing: .08em; padding: 1px 8px; border-radius: 999px; background: var(--rose-gold-100); margin-left: 4px; }
    .code-form { display: flex; gap: 8px; align-items: center; margin: 0 0 18px; }
    .code-form .field { padding: 10px 12px; text-transform: uppercase; }
    .code-form .btn { padding: 10px 18px; white-space: nowrap; }
    .code-form .muted { flex: 1; font-size: 13px; color: var(--ink-muted); }
    .code-error { color: var(--danger); font-size: 13px; margin: -10px 0 16px; }
    .sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
    .free-bar { font-size: 13px; color: var(--rose-gold-700); margin-bottom: 16px; }
    .free-bar .track { height: 4px; background: var(--rose-gold-100); border-radius: 2px; margin-top: 8px; overflow: hidden; }
    .free-bar .fill { height: 100%; background: var(--gradient-gem); }
    .empty { text-align: center; padding: 64px 0; color: var(--ink-muted); }
    .empty p { margin-bottom: 28px; }
    @media (max-width: 860px) { .cart-grid { grid-template-columns: 1fr; gap: 28px; } .line { grid-template-columns: 72px 1fr; } .thumb { width: 72px; } .line-total { grid-column: 2; text-align: left; } }
</style>
@endpush

@if ($added = session('tracking_add'))
    @push('tracking')
        @php
            $trackEvent = ['currency' => $store->currency, 'value' => round($added['price'] * $added['quantity'] / 100, 2), 'items' => [['id' => $added['id'], 'name' => $added['name'], 'price' => round($added['price'] / 100, 2), 'quantity' => $added['quantity']]]];
        @endphp
        lumeeTrack('add_to_cart', @json($trackEvent));
    @endpush
@endif

@section('content')
    @php($m = fn ($v) => $px->display($v))
    <div class="wrap cart">
        <div class="eyebrow">{{ __('Shopping bag') }}</div>
        <h1 class="display">{{ __('Your bag') }}</h1>

        @if (! $cart || $cart->items->isEmpty())
            <div class="empty">
                <p>{{ __('Your bag is empty.') }}</p>
                <a class="btn" href="/">{{ __('Continue shopping') }}</a>
            </div>
        @else
            @php($subtotal = $cart->subtotal())
            <div class="cart-grid">
                <div>
                    @foreach ($cart->items as $item)
                        @php($p = $item->variant->product)
                        <div class="line">
                            <a class="thumb" href="/products/{{ $p->handle }}">
                                @if ($item->imageUrl())
                                    <img src="{{ $item->imageUrl() }}" alt="{{ $p->title }}">
                                @else
                                    <img class="ph" src="{{ $theme->placeholderUrl() }}" alt="">
                                @endif
                            </a>
                            <div>
                                <a class="line-title" href="/products/{{ $p->handle }}">{{ $p->title }}</a>
                                <div class="line-meta">
                                    @if ($item->variant->title !== 'Default'){{ $item->variant->title }} · @endif
                                    <span class="money">{{ $m($item->unitPrice()) }}</span>
                                    @if ($item->plan && $item->unitPrice() < $item->variant->price)<s class="was money">{{ $m($item->variant->price) }}</s>
                                    @elseif ($item->variant->compare_at_price > $item->variant->price)<s class="was money">{{ $m($item->variant->compare_at_price) }}</s>@endif
                                </div>
                                @if ($item->plan)<div class="line-meta plan">↻ {{ $item->planLabel() }}</div>@endif
                                <form class="qty-form" method="post" action="/cart/items/{{ $item->id }}">
                                    @csrf
                                    <input class="field" type="number" name="quantity" min="0" max="10000" value="{{ $item->quantity }}" aria-label="{{ __('Quantity') }}" onchange="this.form.submit()">
                                    <button class="link-btn" type="submit" name="quantity" value="0">{{ __('Remove') }}</button>
                                </form>
                            </div>
                            <div class="line-total money">{{ $m($item->lineTotal()) }}</div>
                        </div>
                    @endforeach
                </div>

                <aside class="summary">
                    @php($discount = $cart->discount())
                    @php($off = $discounts['amount'])
                    {{-- Free-shipping progress uses the amount after discount (same rule as checkout) --}}
                    @php($toward = $subtotal - $off)
                    @if ($nextTier)
                        <div class="free-bar promo-bar">
                            {{ __('Spend :amount more to save :value', ['amount' => $m($nextTier['missing']), 'value' => $nextTier['value_type'] === 'percentage' ? rtrim(rtrim(number_format($nextTier['value'], 2), '0'), '.').'%' : $m((int) round($nextTier['value'] * 100))]) }}
                            <div class="track"><div class="fill" style="width: {{ min(100, round($subtotal / max(1, $nextTier['min']) * 100)) }}%"></div></div>
                        </div>
                    @endif
                    @if ($discount?->type === 'free_shipping')
                        <div class="free-bar">✦ {{ __('Free shipping with code :code', ['code' => $discount->code]) }}</div>
                    @elseif ($freeShippingAt && $toward < $freeShippingAt)
                        <div class="free-bar">
                            {{ __("You're :amount away from free shipping", ['amount' => $m($freeShippingAt - $toward)]) }}
                            <div class="track"><div class="fill" style="width: {{ min(100, round($toward / $freeShippingAt * 100)) }}%"></div></div>
                        </div>
                    @elseif ($freeShippingAt)
                        <div class="free-bar">✦ {{ __("You've unlocked free shipping") }}</div>
                    @endif
                    <div class="summary-row"><span>{{ __('Subtotal') }}</span><span class="money">{{ $m($subtotal) }}</span></div>
                    @foreach ($discounts['lines'] as $line)
                        <div class="summary-row discount-row">
                            <span>{{ $line['type'] === 'code' ? __('Discount') : __('Promotion') }} <span class="code-tag">{{ $line['title'] }}</span></span>
                            <span class="money">−{{ $m($line['amount']) }}</span>
                        </div>
                    @endforeach
                    @if ($discount?->type === 'free_shipping')
                        <div class="summary-row discount-row"><span>{{ __('Discount') }} <span class="code-tag">{{ $discount->code }}</span></span><span>{{ $discount->label($store->currency) }}</span></div>
                    @elseif ($discount && $discounts['code_blocked'])
                        <p class="note" style="margin:-6px 0 12px;text-align:left;">{{ __('Code :code can’t be combined with the current promotion, which saves you more.', ['code' => $discount->code]) }}</p>
                    @endif
                    <div class="summary-row"><span>{{ __('Shipping') }}</span><span>{{ __('Calculated at checkout') }}</span></div>
                    <div class="summary-row total"><span>{{ __('Estimated total') }}</span><span class="money">{{ $m($subtotal - $off) }}</span></div>
                    @if ($px->isConverted())
                        <p class="note" style="margin:-10px 0 16px;text-align:left;">
                            {{ __("Prices are shown in :shown for reference. You'll pay in :currency (:amount before shipping) — your bank sets the final exchange rate.", [
                                'shown' => $px->currency(), 'currency' => $store->currency,
                                'amount' => \App\Support\Money::display($subtotal - $off, $store->currency),
                            ]) }}
                        </p>
                    @endif

                    @if ($giftCard ?? null)
                        <form method="post" action="/cart/gift-card/remove" class="code-form">
                            @csrf
                            <span class="muted">{!! __('Gift card :code applied (balance :balance)', ['code' => '<strong>•••• '.e($giftCard->last4).'</strong>', 'balance' => e(\App\Support\Money::display($giftCard->balance, $store->currency))]) !!}</span>
                            <button type="submit" class="link-btn">{{ __('Remove') }}</button>
                        </form>
                    @endif
                    @if ($discount)
                        <form method="post" action="/cart/discount/remove" class="code-form">
                            @csrf
                            <span class="muted">{!! __('Code :code applied', ['code' => '<strong>'.e($discount->code).'</strong>']) !!}</span>
                            <button type="submit" class="link-btn">{{ __('Remove') }}</button>
                        </form>
                    @else
                        <form method="post" action="/cart/discount" class="code-form">
                            @csrf
                            <label class="sr" for="code">{{ __('Discount code') }}</label>
                            <input id="code" class="field" name="code" value="{{ old('code') }}" placeholder="{{ __('Discount code or gift card') }}" autocomplete="off">
                            <button type="submit" class="btn btn-ghost">{{ __('Apply') }}</button>
                        </form>
                        @error('discount_code')<div class="code-error">{{ $message }}</div>@enderror
                    @endif
                    @if (($loyalty ?? null) && ! ($loyalty['guest'] ?? false) && $loyalty['points'] >= $loyalty['min'] && ! ($giftCard ?? null))
                        <form method="post" action="/account/points/redeem" class="code-form">
                            @csrf
                            <input type="hidden" name="points" value="{{ $loyalty['points'] }}">
                            <span class="muted">{{ __('You have :points points (worth :value).', ['points' => number_format($loyalty['points']), 'value' => $m($loyalty['value'])]) }}</span>
                            <button type="submit" class="link-btn">{{ __('Use them') }}</button>
                        </form>
                    @elseif (($loyalty ?? null) && ($loyalty['guest'] ?? false) && $loyalty['earn_rate'] > 0)
                        <p class="note" style="text-align:left;margin:0 0 12px;"><a href="/account/login">{{ __('Sign in') }}</a> {{ __('to earn points on this order.') }}</p>
                    @endif
                    @if ($wholesale ?? null)
                        <p class="note" style="text-align:left;margin:0 0 12px;">
                            {{ __('Wholesale prices applied.') }}
                            @if ($wholesale['minimum'] > 0 && $subtotal < $wholesale['minimum'])
                                <strong>{{ __('Wholesale orders need a subtotal of at least :amount.', ['amount' => $m($wholesale['minimum'])]) }}</strong>
                            @endif
                        </p>
                    @endif
                    @error('cart')<div class="code-error" style="margin-bottom:12px;">{{ $message }}</div>@enderror
                    @error('quantity')<div class="code-error" style="margin-bottom:12px;">{{ $message }}</div>@enderror
                    <a class="btn btn-block" href="/checkout">{{ __('Checkout') }}</a>
                    @if (app(\App\B2B\B2B::class)->isBusiness())
                        <details class="quote-req" style="margin-top:10px;">
                            <summary class="link-btn" style="cursor:pointer;">{{ __('Need a better price? Request a quote') }}</summary>
                            <form method="post" action="/quotes" style="display:grid;gap:8px;margin-top:8px;">
                                @csrf
                                <textarea class="field" name="note" rows="3" maxlength="2000" placeholder="{{ __('Quantities, delivery date, anything we should know') }}"></textarea>
                                <button class="btn btn-ghost" type="submit">{{ __('Send quote request') }}</button>
                            </form>
                        </details>
                    @endif
                    <div class="note">{{ __('Taxes and duties calculated at checkout') }}</div>
                </aside>
            </div>
            @if (($suggestions ?? collect())->isNotEmpty() && ! app(\App\B2B\B2B::class)->hidePrices())
                <section class="cart-upsell" aria-labelledby="upsell-title">
                    <h2 id="upsell-title">{{ __('You might also like') }}</h2>
                    <div class="upsell-list">
                        @foreach ($suggestions as $sp)
                            <div class="upsell">
                                <a href="{{ route('store.product', $sp->handle, false) }}"><img src="{{ $sp->featured_image ?: $theme->placeholderUrl() }}" alt="" loading="lazy"></a>
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
        @endif
    </div>
@endsection
