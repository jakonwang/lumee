@extends($theme->view('layout'))

@section('title', ($product->seo['title'] ?? null) ?: $product->title.' – '.$store->name)
@section('description', ($product->seo['description'] ?? null) ?: (\App\Support\Seo::text($product->description) ?: $product->title))
@section('og_type', 'product')

@php
    $m = fn ($v) => $px->display($v);   // shopper's display currency
    $variants = $product->variants;
    // Pre-orders: out-of-stock variants stay buyable (up to the optional limit)
    $pre = \App\Catalog\Preorders::config($product);
    $sellable = fn ($v) => $pre['enabled'] ? (\App\Catalog\Preorders::available($v) ?? 99) : (int) $v->inventory_quantity;
    $isPre = fn ($v) => $pre['enabled'] && (int) $v->inventory_quantity <= 0;
    $preNote = $pre['ships_at'] ? __('Pre-order — ships around :date', ['date' => \Illuminate\Support\Carbon::parse($pre['ships_at'])->isoFormat('ll')]) : __('Available for pre-order');
    // Preselect ?variant=ID, else the first in-stock variant, else the first
    $selected = $variants->firstWhere('id', (int) request('variant'))
        ?? $variants->first(fn ($v) => $v->inventory_quantity > 0)
        ?? $variants->first();
    $images = $product->images;
    $mainImage = ($selected?->image_id ? $images->firstWhere('id', $selected->image_id) : null) ?? $images->first();
    $multi = $product->options->isNotEmpty();
    // Variant picker text in the shopper's language (HEX flags: safe inside <script>)
    $jsStrings = json_encode([
        'unavailable' => __('Unavailable'),
        'comboUnavailable' => '● '.__('This combination is unavailable'),
        'save' => __('Save :percent%'),
        'soldOut' => __('Sold out'),
        'add' => __('Add to bag'),
        'onlyLeft' => '● '.__('Only :count left'),
        'inStock' => '● '.__('In stock, ready to ship'),
        'preorder' => __('Pre-order'),
        'preNote' => '● '.$preNote.($pre['note'] ? ' · '.$pre['note'] : ''),
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
    // JSON for the variant picker; HEX flags make it safe to embed inside <script>
    $variantsJson = json_encode($variants->map(fn ($v) => [
        'id' => $v->id,
        'options' => $v->options ?? (object) [],
        'price' => $v->price,
        'compare' => $v->compare_at_price,
        'stock' => $sellable($v),
        'pre' => $isPre($v),
        'image' => $v->image_id ? $images->firstWhere('id', $v->image_id)?->url() : null,
    ])->values(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
@endphp

@if ($mainImage)
    @section('og_image', $mainImage->url())
@endif

@push('head')
@php
    // Structured data for Google Shopping / rich results. JSON_HEX_* keeps it safe inside <script>.
    $abs = fn ($u) => str_starts_with((string) $u, '/') ? $store->storefrontUrl().$u : $u;
    $productLd = [
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $product->title,
        'description' => \App\Support\Seo::text($product->description, 5000),
        'image' => $images->map(fn ($i) => $abs($i->url()))->values()->all(),
        'brand' => $product->vendor ? ['@type' => 'Brand', 'name' => $product->vendor] : null,
        'sku' => $selected?->sku,
        'offers' => $variants->map(fn ($v) => array_filter([
            '@type' => 'Offer',
            'price' => \App\Support\Money::format($v->price),
            'priceCurrency' => $store->currency,
            'sku' => $v->sku,
            'availability' => $v->inventory_quantity > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'url' => $store->storefrontUrl().'/products/'.$product->handle.'?variant='.$v->id,
        ]))->values()->all(),
    ];
    if (($reviewSettings['enabled'] ?? false) && $product->rating_count) {
        $productLd['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => round($product->rating_avg, 1), 'reviewCount' => $product->rating_count, 'bestRating' => 5, 'worstRating' => 1];
        $productLd['review'] = collect($reviews?->items() ?? [])->take(5)->map(fn ($r) => [
            '@type' => 'Review', 'author' => ['@type' => 'Person', 'name' => $r->displayName()],
            'reviewRating' => ['@type' => 'Rating', 'ratingValue' => $r->rating, 'bestRating' => 5],
            'reviewBody' => \Illuminate\Support\Str::limit($r->body, 500), 'datePublished' => $r->created_at->toDateString(),
        ])->values()->all();
    }
@endphp
<script type="application/ld+json">{!! json_encode(array_filter($productLd), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
<style>
    .pdp { display: grid; grid-template-columns: 1.1fr 1fr; gap: 64px; padding-block: 56px; align-items: start; }
    .gallery { position: sticky; top: 96px; }
    .main-img { aspect-ratio: 4 / 5; border-radius: var(--radius-card); background: var(--surface-muted); overflow: hidden; display: flex; align-items: center; justify-content: center; }
    .main-img img { width: 100%; height: 100%; object-fit: cover; }
    .main-img img.ph { width: 40%; height: auto; object-fit: contain; opacity: .5; }
    .thumbs { display: flex; gap: 10px; margin-top: 12px; overflow-x: auto; padding-bottom: 4px; }
    .thumb { flex: none; width: 72px; aspect-ratio: 4 / 5; border-radius: 8px; overflow: hidden; border: 1.5px solid transparent; padding: 0; background: var(--surface-muted); cursor: pointer; }
    .thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .thumb[aria-current="true"] { border-color: var(--rose-gold-500); }
    h1 { font-size: 42px; margin: 10px 0 12px; line-height: 1.15; }
    .sale-banner { display: inline-flex; flex-wrap: wrap; gap: 6px; align-items: center; margin: -4px 0 18px; padding: 8px 14px; border-radius: 999px; background: var(--rose-gold-50); color: var(--rose-gold-700); font-size: 13px; }
    .countdown { font-variant-numeric: tabular-nums; font-weight: 600; }
    .pdp-rating { display: inline-flex; gap: 8px; align-items: center; color: var(--rose-gold-500); text-decoration: none; letter-spacing: 1px; margin-bottom: 12px; }
    .pdp-rating span { color: var(--ink-muted); font-size: 13px; letter-spacing: 0; }
    .price { font-size: 22px; color: var(--rose-gold-700); }
    .was { color: var(--ink-muted); text-decoration: line-through; font-size: 16px; margin-left: 8px; }
    .was:empty { display: none; }
    .save { display: inline-block; margin-left: 10px; font-size: 12px; padding: 2px 10px; border-radius: 999px; background: var(--gem-blush); color: var(--on-accent); vertical-align: middle; }
    .save:empty { display: none; }
    .desc { color: var(--ink-muted); line-height: 1.8; margin: 24px 0; white-space: pre-line; }
    .opt { margin-bottom: 18px; border: 0; padding: 0; }
    .opt legend { font-size: 13px; margin-bottom: 10px; padding: 0; }
    .opt legend span { color: var(--ink-muted); margin-left: 6px; }
    .pills { display: flex; flex-wrap: wrap; gap: 8px; }
    .pill { position: relative; }
    .pill input { position: absolute; opacity: 0; pointer-events: none; }
    .pill span { display: inline-block; padding: 9px 18px; border: 1px solid var(--border); border-radius: 999px; font-size: 14px; background: var(--surface); cursor: pointer; transition: border-color .15s, background .15s; }
    .pill input:checked + span { border-color: var(--rose-gold-500); background: var(--rose-gold-50); color: var(--rose-gold-700); }
    .pill input:focus-visible + span { outline: 2px solid var(--rose-gold-500); outline-offset: 2px; }
    .pill.unavailable span { color: var(--ink-muted); text-decoration: line-through; }
    .stock { font-size: 13px; color: var(--success); margin: 4px 0 18px; }
    .stock.out { color: var(--danger); }
    .stock.low { color: var(--warning); }
    .stock.pre { color: var(--rose-gold-600, var(--rose-gold-500, var(--brand-600))); }
    .buy { display: grid; gap: 12px; max-width: 440px; }
    .buy-row { display: flex; gap: 12px; }
    .buy-row .qty { width: 84px; text-align: center; }
    .buy-row .btn { flex: 1; }
    .btn[disabled] { background: var(--rose-gold-300); cursor: not-allowed; filter: none; }
    .js .variant-select { display: none; }
    .perks { margin-top: 32px; border-top: 1px solid var(--border); padding-top: 20px; font-size: 13px; color: var(--ink-muted); display: grid; gap: 8px; }
    @media (max-width: 860px) {
        .pdp { grid-template-columns: 1fr; gap: 24px; padding-block: 16px; }
        .gallery { position: static; }
        h1 { font-size: 32px; }
    }
</style>
@endpush

@push('tracking')
    @php
        $trackEvent = ['currency' => $store->currency, 'value' => round(((int) $selected?->price) / 100, 2), 'items' => [['id' => $selected?->id ?? $product->id, 'name' => $product->title, 'price' => round(((int) $selected?->price) / 100, 2)]]];
    @endphp
    lumeeTrack('view_item', @json($trackEvent));
@endpush

@section('content')
    <div class="wrap pdp">
        <div class="gallery">
            <div class="main-img">
                @if ($mainImage)
                    <img id="main-img" src="{{ $mainImage->url() }}" alt="{{ $mainImage->alt ?: $product->title }}">
                @else
                    <img class="ph" src="{{ $theme->placeholderUrl() }}" alt="">
                @endif
            </div>
            @if ($images->count() > 1)
                <div class="thumbs" role="list">
                    @foreach ($images as $img)
                        <button type="button" class="thumb" role="listitem" data-image-id="{{ $img->id }}" data-src="{{ $img->url() }}"
                                aria-label="{{ __('Show image :n', ['n' => $loop->iteration]) }}" aria-current="{{ $img->id === $mainImage?->id ? 'true' : 'false' }}">
                            <img src="{{ $img->url() }}" alt="" loading="lazy">
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
                                        <span>{{ $val }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                    @endforeach
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
                        .po .save { color: var(--rose-gold-600, var(--rose-gold-500)); font-weight: 600; margin-left: 6px; }
                        .po-note { margin: 4px 0 6px; font-size: 13px; color: var(--ink-muted); }
                        .line-meta.plan { color: var(--rose-gold-600, var(--rose-gold-500)); }
                    </style>
                    @endpush
                    @endonce
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
                .b2b-rules th, .b2b-rules td { text-align: left; padding: 4px 0; border-bottom: 1px solid var(--border); }
                .b2b-rules th { font-weight: 500; color: var(--ink-muted); font-size: 13px; }
            </style>
            @endpush
            @endonce

            @if ($theme->perks())
                <div class="perks">
                    @foreach ($theme->perks() as $perk)
                        <div>✦ {{ $perk }}</div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    @if ($reviewSettings['enabled'] ?? false)
        @include($theme->view('partials.reviews'), ['customer' => \App\Customers\CustomerSession::user()])
    @endif

    @if (($together ?? collect())->isNotEmpty() && ! ($hidePrices ?? false))
        <section class="wrap fbt" aria-labelledby="fbt-title">
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
                                <img src="{{ $tp->featured_image ?: $theme->placeholderUrl() }}" alt="" loading="lazy">
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

    @if (($related ?? collect())->isNotEmpty())
        <section class="wrap related-products" aria-labelledby="related-title">
            <h2 id="related-title" class="display">{{ __('You may also like') }}</h2>
            @include($theme->view('partials.product-grid'), ['products' => $related, 'columns' => 4])
        </section>
        @push('head')
        <style>
            .related-products { padding-block: 56px 72px; border-top: 1px solid var(--border); }
            .related-products h2 { text-align: center; font-size: clamp(24px, 3vw, 32px); margin: 0 0 32px; }
        </style>
        @endpush
    @endif

    <script>
    (function () {
        const variants = {!! $variantsJson !!};
        const t = {!! $jsStrings !!};
        // Display currency: same conversion + rounding as App\Currency\Presentment
        const px = @json($px->toJs());
        const nf = new Intl.NumberFormat('en-US', { style: 'currency', currency: px.currency });
        const fmt = { format: (units) => {
            let c = Math.round(units * 100 * px.rate);
            if (c > 0 && px.rounding === '1.00') c = Math.ceil(c / 100) * 100;
            if (c > 0 && px.rounding === '0.99') c = (Math.floor(c / 100) + 1) * 100 - 1;
            return nf.format(c / 100);
        } };
        const $ = (s) => document.querySelector(s);
        const main = $('#main-img');

        function showImage(src) {
            if (!main || !src) return;
            main.src = src;
            document.querySelectorAll('.thumb').forEach((t) => t.setAttribute('aria-current', t.dataset.src === src ? 'true' : 'false'));
        }
        document.querySelectorAll('.thumb').forEach((t) => t.addEventListener('click', () => showImage(t.dataset.src)));

        const fieldsets = Array.from(document.querySelectorAll('.opt'));
        if (!fieldsets.length) return;
        document.documentElement.classList.add('js');

        const select = $('.variant-select');
        const hidden = document.createElement('input');
        hidden.type = 'hidden'; hidden.name = 'variant_id';
        select.removeAttribute('name');
        $('#buy').appendChild(hidden);

        function chosen() {
            const sel = {};
            fieldsets.forEach((fs) => {
                const r = fs.querySelector('input:checked');
                if (r) sel[fs.dataset.option] = r.value;
            });
            return sel;
        }

        function update(fromUser) {
            const sel = chosen();
            const v = variants.find((x) => fieldsets.every((fs) => x.options[fs.dataset.option] === sel[fs.dataset.option]));

            // Mark values that would lead to no / sold-out variant given the other selections
            fieldsets.forEach((fs) => {
                const name = fs.dataset.option;
                fs.querySelector('[data-selected-label]').textContent = sel[name] || '';
                fs.querySelectorAll('.pill').forEach((pill) => {
                    const val = pill.querySelector('input').value;
                    const ok = variants.some((x) => x.stock > 0 && x.options[name] === val &&
                        fieldsets.every((o) => o === fs || x.options[o.dataset.option] === sel[o.dataset.option]));
                    pill.classList.toggle('unavailable', !ok);
                });
            });

            const add = $('#add'), stock = $('#stock'), qty = $('#qty');
            if (!v) {
                hidden.value = '';
                add.disabled = true; add.textContent = t.unavailable;
                stock.className = 'stock out'; stock.textContent = t.comboUnavailable;
                return;
            }
            hidden.value = v.id;
            $('#price').textContent = fmt.format(v.price / 100);
            const onSale = v.compare && v.compare > v.price;
            $('#was').textContent = onSale ? fmt.format(v.compare / 100) : '';
            $('#save').textContent = onSale ? t.save.replace(':percent', Math.round(100 - v.price / v.compare * 100)) : '';
            add.disabled = v.stock <= 0;
            add.textContent = v.stock <= 0 ? t.soldOut : v.pre ? t.preorder : t.add;
            stock.className = 'stock ' + (v.pre && v.stock > 0 ? 'pre' : v.stock <= 0 ? 'out' : v.stock <= 5 ? 'low' : '');
            stock.textContent = v.pre && v.stock > 0 ? t.preNote : v.stock <= 0 ? '● ' + t.soldOut : v.stock <= 5 ? t.onlyLeft.replace(':count', v.stock) : t.inStock;
            qty.max = Math.max(1, Math.min(99, v.stock));
            if (Number(qty.value) > Number(qty.max)) qty.value = qty.max;
            if (v.image) showImage(v.image);
            if (fromUser) history.replaceState(null, '', '?variant=' + v.id);
        }

        fieldsets.forEach((fs) => fs.addEventListener('change', () => update(true)));
        update(false);
    })();
        // Sale countdown
    document.querySelectorAll('.countdown[data-ends]').forEach(function (el) {
        const end = Date.parse(el.dataset.ends);
        const pad = (n) => String(n).padStart(2, '0');
        const tick = () => {
            let s = Math.max(0, Math.floor((end - Date.now()) / 1000));
            const d = Math.floor(s / 86400); s %= 86400;
            el.textContent = (d ? d + el.dataset.day + ' ' : '') + pad(Math.floor(s / 3600)) + ':' + pad(Math.floor(s % 3600 / 60)) + ':' + pad(s % 60);
            if (end > Date.now()) setTimeout(tick, 1000);
        };
        tick();
    });
</script>
@endsection
