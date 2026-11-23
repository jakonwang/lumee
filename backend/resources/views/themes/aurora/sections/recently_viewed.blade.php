{{-- Recently viewed: products this browser looked at (kept in localStorage by the product page), filled in by JS —
     so the page stays the same for everyone and can be cached. Hidden until there is something to show. --}}
@php
    $title = $s['heading'] === 'Recently viewed' ? __('Recently viewed') : $s['heading'];
@endphp
<section class="wrap recent-viewed" data-section-id="{{ $section['id'] }}" data-limit="{{ (int) $s['limit'] }}"
    data-current="{{ isset($product) ? $product->handle : '' }}" data-sold-out="{{ __('Sold out') }}" data-api="{{ app(\App\I18n\Localization::class)->url('/wishlist/products') }}" hidden aria-labelledby="{{ $section['id'] }}-title">
    @if ($title)<h2 id="{{ $section['id'] }}-title" class="display">{{ $title }}</h2>@endif
    <div class="rv-grid cols-{{ (int) $s['columns'] }} m-cols-{{ (int) $s['mobile_columns'] }}"></div>
</section>

@once
@push('head')
<style>
    .recent-viewed { padding-block: 48px 64px; border-top: 1px solid var(--border); }
    .recent-viewed h2 { margin: 0 0 24px; font-size: 26px; }
    .rv-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; }
    .rv-grid.cols-3 { grid-template-columns: repeat(3, 1fr); }
    .rv-grid.cols-5 { grid-template-columns: repeat(5, 1fr); }
    .rv-card { display: block; color: inherit; text-decoration: none; }
    .rv-card .img { aspect-ratio: 4 / 5; border-radius: var(--radius-card); background: var(--surface-muted); overflow: hidden; }
    .rv-card img { width: 100%; height: 100%; object-fit: cover; }
    .rv-card span { display: block; margin-top: 10px; font-size: 14px; }
    .rv-card .price { margin-top: 4px; font-weight: 600; }
    @media (max-width: 720px) {
        .rv-grid, .rv-grid.cols-3, .rv-grid.cols-5 { grid-template-columns: repeat(2, 1fr); gap: 14px; }
        .rv-grid.m-cols-1 { grid-template-columns: 1fr; }
    }
</style>
@endpush
@push('scripts')
<script>
    document.querySelectorAll('.recent-viewed').forEach(function (box) {
        var handles = [];
        try { handles = JSON.parse(localStorage.getItem('lumee_recent') || '[]'); } catch (e) {}
        handles = handles.filter(function (h) { return h !== box.dataset.current; }).slice(0, Number(box.dataset.limit) || 6);
        if (!handles.length) return;
        var esc = function (s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
        fetch(box.dataset.api + '?handles=' + encodeURIComponent(handles.join(',')), { headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                var by = {};
                res.data.forEach(function (p) { by[p.handle] = p; });
                var items = handles.map(function (h) { return by[h]; }).filter(Boolean);
                if (!items.length) return;
                box.querySelector('.rv-grid').innerHTML = items.map(function (p) {
                    return '<a class="rv-card" href="' + esc(p.url) + '"><div class="img">'
                        + (p.image ? '<img src="' + esc(p.image_sm || p.image) + '" alt="" loading="lazy">' : '') + '</div>'
                        + '<span>' + esc(p.title) + '</span><span class="price">' + esc(p.sold_out ? box.dataset.soldOut : p.price) + '</span></a>';
                }).join('');
                box.hidden = false;
            })
            .catch(function () {});
    });
</script>
@endpush
@endonce
