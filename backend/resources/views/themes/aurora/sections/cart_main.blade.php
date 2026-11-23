{{-- Cart › the bag, totals and checkout button (always on the page) --}}
@php($m = fn ($v) => $px->display($v))
<div class="wrap cart" data-section-id="{{ $section['id'] ?? 'cart' }}">
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
                                <img {{ \App\Media\Img::attrs($item->imageUrl(), 'thumb') }} alt="{{ $p->title }}">
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
                            @if (! empty($item->gift['email']))<div class="line-meta">🎁 {{ __('Gift for :name', ['name' => $item->gift['name'] ?? $item->gift['email']]) }}</div>@endif
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
                    <p class="note" style="margin:-6px 0 12px;text-align: start;">{{ __('Code :code can’t be combined with the current promotion, which saves you more.', ['code' => $discount->code]) }}</p>
                @endif
                <div class="summary-row"><span>{{ __('Shipping') }}</span><span>{{ __('Calculated at checkout') }}</span></div>
                <div class="summary-row total"><span>{{ __('Estimated total') }}</span><span class="money">{{ $m($subtotal - $off) }}</span></div>
                @if ($px->isConverted())
                    <p class="note" style="margin:-10px 0 16px;text-align: start;">
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
                    <p class="note" style="text-align: start;margin:0 0 12px;"><a href="/account/login">{{ __('Sign in') }}</a> {{ __('to earn points on this order.') }}</p>
                @endif
                @if ($wholesale ?? null)
                    <p class="note" style="text-align: start;margin:0 0 12px;">
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
    @endif
</div>
