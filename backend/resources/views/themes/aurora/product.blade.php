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
    // Size conversion (products with a size chart): the shopper's own size system inside a market
    $sizeOption = $product->size_chart
        ? $product->options->first(fn ($o) => preg_match('/size|talla|tamanho|taille|gr(ö|oe)(ss|ß)e|taglia|尺码|尺寸|码/iu', (string) $o->name))?->name
        : null;
    $sizeMarket = app(\App\Markets\MarketContext::class)->market();
    $sizeSystem = $sizeMarket ? \App\Catalog\SizeCharts::systemFor((array) $sizeMarket->countries) : null;
    $sizeGuide = $sizeOption ? \App\Catalog\SizeCharts::guide($product->size_chart, (array) $product->options->firstWhere('name', $sizeOption)?->values, $sizeSystem) : null;
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
    // EU sites: the lowest price of the 30 days before a discount, next to the sale price
    $lowest = \App\Catalog\PriceHistory::applies() ? \App\Catalog\PriceHistory::lowestBefore($variants->pluck('id')->all()) : [];
    // JSON for the variant picker; HEX flags make it safe to embed inside <script>
    $variantsJson = json_encode($variants->map(fn ($v) => [
        'id' => $v->id,
        'options' => $v->options ?? (object) [],
        'price' => $v->price,
        'compare' => $v->compare_at_price,
        'low' => $lowest[$v->id] ?? null,
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
    .was { color: var(--ink-muted); text-decoration: line-through; font-size: 16px; margin-inline-start: 8px; }
    .was:empty { display: none; }
    .lowest { margin: 4px 0 0; font-size: 12.5px; color: var(--ink-muted); }
    .save { display: inline-block; margin-inline-start: 10px; font-size: 12px; padding: 2px 10px; border-radius: 999px; background: var(--gem-blush); color: var(--on-accent); vertical-align: middle; }
    .save:empty { display: none; }
    .desc { color: var(--ink-muted); line-height: 1.8; margin: 24px 0; white-space: pre-line; }
    .opt { margin-bottom: 18px; border: 0; padding: 0; }
    .opt legend { font-size: 13px; margin-bottom: 10px; padding: 0; }
    .opt legend span { color: var(--ink-muted); margin-inline-start: 6px; }
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
    {{-- Blocks of the product page template (theme editor › 商品页); the product info block is always there --}}
    @include($theme->view('partials.blocks'), ['blocks' => $theme->templateSections('product')])

    <script>
    (function () {
        const variants = {!! $variantsJson !!};
        const t = {!! $jsStrings !!};
        // Display currency: same conversion + rounding as App\Currency\Presentment
        const px = @json($px->toJs());
        const nf = new Intl.NumberFormat(@json(\App\Support\Money::locale() ?? 'en-US'), { style: 'currency', currency: px.currency });   // same as Money::display
        const fmt = { format: (units) => {
            let c = Math.round(units * 100 * px.rate);
            if (c > 0 && px.rounding === '1.00') c = Math.ceil(c / 100) * 100;
            if (c > 0 && px.rounding === '0.99') c = (Math.floor(c / 100) + 1) * 100 - 1;
            return nf.format(c / 100);
        } };
        const $ = (s) => document.querySelector(s);
        try {   // "Recently viewed": this browser only, newest first
            const k = 'lumee_recent', h = @json($product->handle);
            const list = JSON.parse(localStorage.getItem(k) || '[]').filter((x) => x !== h);
            localStorage.setItem(k, JSON.stringify([h, ...list].slice(0, 20)));
        } catch (e) {}
        const main = $('#main-img');

        function showImage(src) {
            if (!main || !src) return;
            // Uploaded images come in several sizes: use the thumbnail's set so the browser picks the right one
            const t = Array.from(document.querySelectorAll('.thumb')).find((x) => x.dataset.src === src);
            if (t && t.dataset.srcset) { main.srcset = t.dataset.srcset; main.src = t.dataset.main; } else { main.removeAttribute('srcset'); main.src = src; }
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
            const inst = document.getElementById('installments');
            if (inst) {
                const n = Number(inst.dataset.count);
                const ok = v.price > 0 && v.price >= Number(inst.dataset.min);
                inst.hidden = !ok;
                if (ok) inst.textContent = inst.dataset[inst.dataset.interestFree === '1' ? 'free' : 'paid'].replace('__N__', n).replace('__A__', fmt.format(Math.ceil(v.price / n) / 100));
            }
            const onSale = v.compare && v.compare > v.price;
            $('#was').textContent = onSale ? fmt.format(v.compare / 100) : '';
            $('#save').textContent = onSale ? t.save.replace(':percent', Math.round(100 - v.price / v.compare * 100)) : '';
            const low = document.getElementById('lowest');
            if (low) { low.hidden = !(onSale && v.low); if (onSale && v.low) low.textContent = low.dataset.text.replace('__P__', fmt.format(v.low / 100)); }
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
