{{-- Product page › product info (gallery, price, options, buy box). Uses the variables prepared in product.blade.php. --}}
<div class="wrap pdp" data-section-id="{{ $section['id'] ?? 'main' }}">
    <div class="gallery">
        <div class="main-img">
            @if ($mainImage)
                <img id="main-img" {{ \App\Media\Img::attrs($mainImage->url(), 'product') }} alt="{{ $mainImage->alt ?: $product->title }}" fetchpriority="high">
            @else
                <img class="ph" src="{{ $theme->placeholderUrl() }}" alt="">
            @endif
        </div>
        @if ($images->count() > 1)
            <div class="thumbs" role="list">
                @foreach ($images as $img)
                    <button type="button" class="thumb" role="listitem" data-image-id="{{ $img->id }}" data-src="{{ $img->url() }}" data-main="{{ \App\Media\Img::url($img->url(), 960) }}" data-srcset="{{ \App\Media\Img::path($img->url()) ? collect(\App\Media\Img::PRESETS['product'][0])->map(fn ($w) => \App\Media\Img::url($img->url(), $w).' '.$w.'w')->implode(', ') : '' }}"
                            aria-label="{{ __('Show image :n', ['n' => $loop->iteration]) }}" aria-current="{{ $img->id === $mainImage?->id ? 'true' : 'false' }}">
                        <img {{ \App\Media\Img::attrs($img->url(), 'thumb') }} alt="" loading="lazy">
                    </button>
                @endforeach
            </div>
        @endif
    </div>

    <div>
        <div class="eyebrow">{{ $product->product_type ?: $store->name }}</div>
        <h1 class="display">{{ $product->title }}</h1>
        @if (($reviewSettings['enabled'] ?? false) && $product->rating_count)
            <a href="#reviews" class="pdp-rating">{{ str_repeat('★', (int) round($product->rating_avg)).str_repeat('☆', 5 - (int) round($product->rating_avg)) }} <span>{{ number_format($product->rating_avg, 1) }} · {{ trans_choice(':count review|:count reviews', $product->rating_count, ['count' => $product->rating_count]) }}</span></a>
        @endif
        @php
            $b2b = app(\App\B2B\B2B::class);
            $hidePrices = $b2b->hidePrices();
            $rules = $selected && $b2b->isBusiness() ? app(\App\Pricing\PriceLists::class)->rules($selected->id) : null;
        @endphp
        @if ($hidePrices)
            <p class="b2b-signin"><a href="/account/login?return={{ urlencode('/products/'.$product->handle) }}">{{ __('Sign in to see prices and order') }}</a></p>
        @endif
        <div class="price" @if ($hidePrices) hidden @endif>
            <span id="price">{{ $m($selected?->price) }}</span>
            <span class="was" id="was">{{ $selected?->compare_at_price > $selected?->price ? $m($selected->compare_at_price) : '' }}</span>
            <span class="save" id="save">{{ $selected?->compare_at_price > $selected?->price ? __('Save :percent%', ['percent' => round(100 - $selected->price / $selected->compare_at_price * 100)]) : '' }}</span>
        </div>
        @if (isset($lowest) && $lowest && ! $hidePrices)
            {{-- EU: lowest price of the 30 days before the discount (Omnibus directive) --}}
            @php $low = $selected ? ($lowest[$selected->id] ?? null) : null; @endphp
            <p class="lowest" id="lowest" data-text="{{ __('Lowest price in the 30 days before the discount: :price', ['price' => '__P__']) }}" @if (! $low || ! ($selected->compare_at_price > $selected->price)) hidden @endif>{{ $low ? __('Lowest price in the 30 days before the discount: :price', ['price' => $m($low)]) : '' }}</p>
        @endif
        @php
            $installments = \App\Payments\Installments::settings();
            $offer = $selected && ! $hidePrices ? \App\Payments\Installments::offer((int) $selected->price) : null;
        @endphp
        @if ($installments && ! $hidePrices)
            {{-- Card installments (MSI / parcelamento): updated when the variant changes --}}
            <p class="installments" id="installments" data-count="{{ $installments['months'] }}" data-min="{{ $installments['min'] }}"
               data-free="{{ __('or :count interest-free installments of :amount', ['count' => '__N__', 'amount' => '__A__']) }}"
               data-paid="{{ __('or :count installments of :amount', ['count' => '__N__', 'amount' => '__A__']) }}"
               data-interest-free="{{ $installments['interest_free'] ? '1' : '0' }}" @if (! $offer) hidden @endif>
                @if ($offer){{ $offer['interest_free'] ? __('or :count interest-free installments of :amount', ['count' => $offer['count'], 'amount' => $m($offer['each'])]) : __('or :count installments of :amount', ['count' => $offer['count'], 'amount' => $m($offer['each'])]) }}@endif
            </p>
            @push('head')
            <style>.installments { margin: -4px 0 12px; font-size: 14px; color: var(--ink-muted); }</style>
            @endpush
        @endif
        @php
            $sale = $selected ? app(\App\Promotions\Promotions::class)->saleFor($product->id, (int) $selected->getRawOriginal('price')) : null;
        @endphp
        @if ($sale)
            <div class="sale-banner">
                <strong>{{ $sale->title }}</strong>
                @if ($sale->ends_at && ($sale->config['countdown'] ?? false))
                    · {{ __('Ends in') }} <span class="countdown" data-ends="{{ $sale->ends_at->toIso8601String() }}" data-day="{{ __('d') }}">{{ $sale->ends_at->diffForHumans(null, true) }}</span>
                @endif
            </div>
        @endif

        @if ($product->description)
            <div class="desc">{{ $product->description }}</div>
        @endif

        <form method="post" action="/cart/add" class="buy" id="buy">
            @csrf
            @if ($multi)
                @foreach ($product->options as $opt)
                    <fieldset class="opt" data-option="{{ $opt->name }}">
                        <legend>{{ $opt->name }}<span data-selected-label>{{ $selected?->options[$opt->name] ?? '' }}</span></legend>
                        <div class="pills">
                            @foreach ($opt->values as $val)
                                <label class="pill">
                                    <input type="radio" name="opt_{{ $loop->parent->index }}" value="{{ $val }}" @checked(($selected?->options[$opt->name] ?? null) === $val)>
                                    <span>{{ $val }}@if ($sizeOption === $opt->name && $sizeSystem && ($local = \App\Catalog\SizeCharts::convert($product->size_chart, $val, $sizeSystem)))<small class="local-size"> · {{ $sizeSystem }} {{ $local }}</small>@endif</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endforeach
                @if ($sizeGuide)
                    {{-- Size guide: the US sizes this product comes in, in every system (the shopper's first) --}}
                    <details class="size-guide">
                        <summary>{{ __('Size guide') }}</summary>
                        <table>
                            <thead><tr>@foreach ($sizeGuide['systems'] as $sys)<th>{{ $sys }}</th>@endforeach</tr></thead>
                            <tbody>@foreach ($sizeGuide['rows'] as $row)<tr>@foreach ($sizeGuide['systems'] as $sys)<td>{{ $row[$sys] }}</td>@endforeach</tr>@endforeach</tbody>
                        </table>
                        <p>{{ __('Standard conversions — sizes can vary slightly between makers.') }}</p>
                    </details>
                    <style>
                        .size-guide { margin: 4px 0 14px; font-size: 14px; }
                        .size-guide summary { cursor: pointer; color: var(--ink-muted); text-decoration: underline; }
                        .size-guide table { border-collapse: collapse; margin-top: 8px; width: 100%; max-width: 420px; }
                        .size-guide th, .size-guide td { border: 1px solid var(--border); padding: 4px 8px; text-align: center; }
                        .size-guide th { background: var(--surface-muted, #f6f6f6); font-weight: 600; }
                        .size-guide p { font-size: 12px; color: var(--ink-muted); margin: 6px 0 0; }
                        .local-size { opacity: .7; }
                    </style>
                @endif
                {{-- No-JS fallback; hidden once JS takes over --}}
                <select name="variant_id" class="field variant-select" aria-label="{{ __('Choose an option') }}">
                    @foreach ($variants as $v)
                        <option value="{{ $v->id }}" @selected($v->id === $selected?->id) @disabled($sellable($v) <= 0)>
                            {{ $v->title }} — {{ $m($v->price) }}{{ $isPre($v) ? ' ('.__('Pre-order').')' : ($v->inventory_quantity <= 0 ? ' ('.__('Sold out').')' : '') }}
                        </option>
                    @endforeach
                </select>
            @else
                <input type="hidden" name="variant_id" value="{{ $selected?->id }}">
            @endif

            @php
                $sub = \App\Subscriptions\Plans::config($product);
            @endphp
            @if ($sub['enabled'])
                <fieldset class="purchase-options">
                    <legend class="sr">{{ __('Purchase options') }}</legend>
                    @unless ($sub['only'])
                        <label class="po"><input type="radio" name="plan" value="" checked> <span>{{ __('One-time purchase') }}</span></label>
                    @endunless
                    @foreach ($sub['intervals'] as $i => $plan)
                        <label class="po"><input type="radio" name="plan" value="{{ $plan }}" @checked($sub['only'] && $i === 0)>
                            <span>{{ \App\Subscriptions\Plans::label($plan) }}@if ($sub['discount'] > 0) <strong class="save">{{ __('Save :percent%', ['percent' => rtrim(rtrim(number_format($sub['discount'], 2), '0'), '.')]) }}</strong>@endif</span></label>
                    @endforeach
                    <p class="po-note">{{ __('Subscriptions renew automatically. Skip, pause or cancel any time.') }}</p>
                </fieldset>
                @once
                @push('head')
                <style>
                    .purchase-options { border: 1px solid var(--border); border-radius: var(--radius-card, 12px); padding: 8px 14px; margin: 16px 0; display: grid; gap: 2px; }
                    .po { display: flex; align-items: center; gap: 10px; padding: 8px 0; cursor: pointer; font-size: 15px; }
                    .po + .po { border-top: 1px solid var(--border); }
                    .po .save { color: var(--rose-gold-600, var(--rose-gold-500)); font-weight: 600; margin-inline-start: 6px; }
                    .po-note { margin: 4px 0 6px; font-size: 13px; color: var(--ink-muted); }
                    .line-meta.plan { color: var(--rose-gold-600, var(--rose-gold-500)); }
                </style>
                @endpush
                @endonce
            @endif

            @if ($product->is_gift_card)
                {{-- Gift card for someone else: we email it to them with the buyer's message --}}
                <details class="gift-to" @if (old('gift.email')) open @endif>
                    <summary>{{ __('Send it to someone as a gift') }}</summary>
                    <div class="gift-fields">
                        <input class="field" type="email" name="gift[email]" value="{{ old('gift.email') }}" maxlength="255" placeholder="{{ __('Recipient email') }}" aria-label="{{ __('Recipient email') }}">
                        <input class="field" name="gift[name]" value="{{ old('gift.name') }}" maxlength="100" placeholder="{{ __('Recipient name (optional)') }}" aria-label="{{ __('Recipient name (optional)') }}">
                        <textarea class="field" name="gift[message]" maxlength="500" rows="3" placeholder="{{ __('Message (optional)') }}" aria-label="{{ __('Message (optional)') }}">{{ old('gift.message') }}</textarea>
                        <p class="gift-note">{{ __('We email the gift card to them once your order is paid. Leave this empty to receive it yourself.') }}</p>
                    </div>
                </details>
                @push('head')
                <style>
                    .gift-to { border: 1px solid var(--border); border-radius: var(--radius-card, 12px); padding: 10px 14px; margin: 16px 0; }
                    .gift-to summary { cursor: pointer; font-size: 15px; }
                    .gift-fields { display: grid; gap: 8px; margin-top: 10px; }
                    .gift-fields .field { width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid var(--border); border-radius: 8px; font: inherit; background: transparent; color: inherit; }
                    .gift-note { margin: 0; font-size: 13px; color: var(--ink-muted); }
                </style>
                @endpush
            @endif

            @php
                $stock = $selected ? $sellable($selected) : 0;
                $selPre = $selected && $isPre($selected);
            @endphp
            <div id="stock" class="stock {{ $selPre ? 'pre' : ($stock <= 0 ? 'out' : ($stock <= 5 ? 'low' : '')) }}">
                @if ($selPre)● {{ $preNote }}@if ($pre['note']) · {{ $pre['note'] }}@endif
                @else{{ $stock <= 0 ? '● '.__('Sold out') : ($stock <= 5 ? '● '.__('Only :count left', ['count' => $stock]) : '● '.__('In stock, ready to ship')) }}@endif
            </div>

            @if ($rules && ($rules['tiers'] || $rules['min'] > 1 || $rules['step'] > 1))
                <div class="b2b-rules">
                    @if ($rules['min'] > 1 || $rules['step'] > 1)
                        <div>{{ $rules['min'] > 1 ? __('Minimum order: :count', ['count' => $rules['min']]) : '' }}{{ $rules['min'] > 1 && $rules['step'] > 1 ? ' · ' : '' }}{{ $rules['step'] > 1 ? __('Sold in multiples of :count', ['count' => $rules['step']]) : '' }}</div>
                    @endif
                    @if ($rules['tiers'])
                        <table>
                            <thead><tr><th>{{ __('Quantity') }}</th><th>{{ __('Price each') }}</th></tr></thead>
                            <tbody>
                                <tr><td>{{ $rules['min'] }}+</td><td>{{ $m($selected->price) }}</td></tr>
                                @foreach ($rules['tiers'] as $tier)
                                    <tr><td>{{ $tier['min'] }}+</td><td>{{ $m(min($tier['price'], $selected->price)) }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            @endif

            @if ($hidePrices)
                <a class="btn" href="/account/login?return={{ urlencode('/products/'.$product->handle) }}">{{ __('Sign in to order') }}</a>
            @else
            <div class="buy-row">
                <input class="field qty" id="qty" type="number" name="quantity" value="{{ $rules['min'] ?? 1 }}" min="{{ $rules['min'] ?? 1 }}" step="{{ $rules['step'] ?? 1 }}"
                    max="{{ max(1, $rules ? $stock : min(99, $stock)) }}" aria-label="{{ __('Quantity') }}">
                <button class="btn" id="add" type="submit" @disabled($stock <= 0)>{{ $stock <= 0 ? __('Sold out') : ($selPre ? __('Pre-order') : __('Add to bag')) }}</button>
                <button type="button" class="wish-btn" data-wish="{{ $product->handle }}" aria-pressed="false" aria-label="{{ __('Save to wishlist') }}" title="{{ __('Save to wishlist') }}">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M12 20s-7-4.4-9.2-9A5 5 0 0 1 12 6a5 5 0 0 1 9.2 5C19 15.6 12 20 12 20Z"/></svg>
                </button>
            </div>
            @endif
        </form>
        @php
            $soldOutVariants = $product->variants->filter(fn ($v) => $sellable($v) <= 0);
        @endphp
        @if ($soldOutVariants->isNotEmpty() && ! ($hidePrices ?? false))
            <form method="post" action="/products/{{ $product->handle }}/notify" class="notify-me" id="notify">
                @csrf
                @if (session('notify_status'))
                    <p class="notify-ok" role="status">✓ {{ session('notify_status') }}</p>
                @else
                    <p class="notify-title">{{ $soldOutVariants->count() === $product->variants->count() ? __('Sold out — get an email when it’s back') : __('Option sold out? Get an email when it’s back') }}</p>
                    @if ($soldOutVariants->count() > 1 || $product->variants->count() > 1)
                        <select class="field" name="variant_id" aria-label="{{ __('Choose an option') }}">
                            @foreach ($soldOutVariants as $v)<option value="{{ $v->id }}" @selected($v->id === $selected?->id)>{{ $v->title }}</option>@endforeach
                        </select>
                    @else
                        <input type="hidden" name="variant_id" value="{{ $soldOutVariants->first()->id }}">
                    @endif
                    <div class="notify-row">
                        <input class="field" type="email" name="email" required placeholder="{{ __('Your email') }}" value="{{ \App\Customers\CustomerSession::user()?->email }}" aria-label="{{ __('Your email') }}">
                        <button class="btn btn-ghost" type="submit">{{ __('Notify me') }}</button>
                    </div>
                    @error('email')<p class="notify-err">{{ $message }}</p>@enderror
                @endif
            </form>
        @endif
        @once
        @push('head')
        <style>
            .notify-me { margin: 16px 0; padding: 14px; border: 1px dashed var(--border); border-radius: var(--radius-card, 12px); display: grid; gap: 8px; }
            .notify-title { margin: 0; font-size: 14px; }
            .notify-row { display: flex; gap: 8px; }
            .notify-row .field { flex: 1; }
            .notify-ok { margin: 0; color: var(--rose-gold-600, var(--rose-gold-500)); font-size: 14px; }
            .notify-err { margin: 0; color: #B42318; font-size: 13px; }
            .b2b-signin { margin: 8px 0 16px; font-size: 16px; }
            .b2b-rules { margin: 14px 0; padding: 12px 14px; border: 1px solid var(--border); border-radius: var(--radius-card, 12px); font-size: 14px; display: grid; gap: 8px; }
            .b2b-rules table { width: 100%; border-collapse: collapse; }
            .b2b-rules th, .b2b-rules td { text-align: start; padding: 4px 0; border-bottom: 1px solid var(--border); }
            .b2b-rules th { font-weight: 500; color: var(--ink-muted); font-size: 13px; }
        </style>
        @endpush
        @endonce

        @if (($s['show_perks'] ?? true) && $theme->perks())
            <div class="perks">
                @foreach ($theme->perks() as $perk)
                    <div>✦ {{ $perk }}</div>
                @endforeach
            </div>
        @endif
    </div>
</div>
