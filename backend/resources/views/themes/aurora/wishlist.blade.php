@extends($theme->view('layout'))

@section('title', __('Wishlist').' · '.$store->name)
@section('noindex', '1')

@push('head')
<style>
    .wishlist { padding-block: 56px 80px; }
    .wishlist h1 { font-size: clamp(30px, 4vw, 42px); margin: 0 0 8px; text-align: center; }
    .wishlist .lead { text-align: center; color: var(--ink-muted); margin: 0 0 36px; }
    .wish-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 24px; }
    .wish-card { position: relative; display: grid; gap: 8px; }
    .wish-card a { text-decoration: none; color: inherit; display: grid; gap: 8px; }
    .wish-card .img { aspect-ratio: 1; border-radius: var(--radius-card, 12px); background: var(--surface-muted); overflow: hidden; }
    .wish-card img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .wish-card .price { color: var(--ink-muted); font-size: 14px; }
    .wish-card .remove { position: absolute; top: 8px; right: 8px; width: 34px; height: 34px; border-radius: 50%; border: 0; background: rgba(255,255,255,.92); cursor: pointer; font-size: 18px; color: var(--ink); }
    .wish-empty { text-align: center; color: var(--ink-muted); padding: 40px 0; }
    @media (max-width: 900px) { .wish-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
@endpush

@section('content')
    <section class="wrap wishlist">
        <h1 class="display">{{ __('Wishlist') }}</h1>
        <p class="lead">{{ __('Items you saved on this device.') }}</p>
        <div id="wish-grid" class="wish-grid" aria-live="polite"></div>
        <div id="wish-empty" class="wish-empty" hidden>
            <p>{{ __('Your wishlist is empty. Tap the heart on any product to save it here.') }}</p>
            <a class="btn" href="/">{{ __('Continue shopping') }}</a>
        </div>
    </section>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const grid = document.getElementById('wish-grid');
        const empty = document.getElementById('wish-empty');
        const esc = (s) => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
        const removeLabel = @json(__('Remove'));
        const soldOut = @json(__('Sold out'));
        function render() {
            const handles = window.lumeeWishlist.read();
            if (!handles.length) { grid.innerHTML = ''; empty.hidden = false; return; }
            fetch(@json(app(\App\I18n\Localization::class)->url('/wishlist/products')) + '?handles=' + encodeURIComponent(handles.join(',')), { headers: { Accept: 'application/json' } })
                .then((r) => r.json())
                .then((res) => {
                    const byHandle = Object.fromEntries(res.data.map((p) => [p.handle, p]));
                    const items = handles.map((h) => byHandle[h]).filter(Boolean);
                    empty.hidden = items.length > 0;
                    grid.innerHTML = items.map((p) => '<div class="wish-card">'
                        + '<a href="' + esc(p.url) + '"><div class="img">' + (p.image ? '<img src="' + esc(p.image_sm || p.image) + '" alt="" loading="lazy">' : '') + '</div>'
                        + '<span>' + esc(p.title) + '</span><span class="price">' + esc(p.sold_out ? soldOut : p.price) + '</span></a>'
                        + '<button type="button" class="remove" data-wish="' + esc(p.handle) + '" aria-label="' + esc(removeLabel) + '">×</button></div>').join('');
                });
        }
        grid.addEventListener('click', (e) => { if (e.target.closest('[data-wish]')) setTimeout(render, 0); });
        render();
    });
    </script>
@endsection
