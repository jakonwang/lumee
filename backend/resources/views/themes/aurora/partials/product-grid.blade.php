{{-- Product card grid. Expects $products (with variants loaded), optional $columns (3|4|5) and $mobileColumns (1|2). --}}
<div class="grid cols-{{ $columns ?? 4 }} m-cols-{{ $mobileColumns ?? 2 }}">
    @foreach ($products as $product)
        @php($min = $product->variants->min('price'))
        @php($cheapest = $product->variants->firstWhere('price', $min))
        @php($preorder = \App\Catalog\Preorders::config($product)['enabled'])
        @php($soldOut = ! $preorder && $product->variants->sum('inventory_quantity') <= 0)
        <a class="card" href="{{ route('store.product', $product->handle, false) }}">
            <div class="img">
                @if ($product->featured_image)
                    <img {{ \App\Media\Img::attrs($product->featured_image, 'card') }} alt="{{ $product->title }}" loading="lazy">
                @else
                    <img class="ph" src="{{ $theme->placeholderUrl() }}" alt="">
                @endif
                @if ($cheapest?->compare_at_price > $min && ! $soldOut)<span class="badge">{{ __('Sale') }}</span>@endif
            </div>
            <h3>{{ $product->title }}</h3>
            <div class="price">
                @if (app(\App\B2B\B2B::class)->hidePrices())
                    {{ __('Sign in for prices') }}
                @else
                {{ $product->variants->max('price') > $min ? __('From :price', ['price' => $px->display($min)]) : $px->display($min) }}
                @if ($cheapest?->compare_at_price > $min)
                    <span class="was">{{ $px->display($cheapest->compare_at_price) }}</span>
                @endif
                @endif
                @if ($soldOut)<span class="sold">{{ __('Sold out') }}</span>@elseif ($preorder && $product->variants->sum('inventory_quantity') <= 0)<span class="sold">{{ __('Pre-order') }}</span>@endif
            </div>
        </a>
    @endforeach
</div>

@once
@push('head')
<style>
    .grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 24px; }
    .grid.cols-3 { grid-template-columns: repeat(3, 1fr); }
    .grid.cols-5 { grid-template-columns: repeat(5, 1fr); gap: 18px; }
    .card .img { position: relative; aspect-ratio: 4 / 5; border-radius: var(--radius-card); background: var(--surface-muted); overflow: hidden; display: flex; align-items: center; justify-content: center; }
    .card .img img { width: 100%; height: 100%; object-fit: cover; transition: transform .6s ease; }
    .card:hover .img img { transform: scale(1.04); }
    .card .img img.ph { width: 40%; height: auto; object-fit: contain; opacity: .5; }
    .card .badge { position: absolute; top: 12px; inset-inline-start: 12px; padding: 3px 12px; border-radius: 999px; font-size: 11px; letter-spacing: .12em; text-transform: uppercase; background: var(--gem-blush); color: var(--on-accent); }
    .card h3 { font-weight: 500; font-size: 15px; margin: 14px 0 4px; }
    .card .price { color: var(--rose-gold-700); font-size: 14px; }
    .card .was { color: var(--ink-muted); text-decoration: line-through; margin-inline-start: 6px; font-size: 13px; }
    .card .sold { margin-inline-start: 8px; font-size: 12px; color: var(--ink-muted); }
    .empty { text-align: center; color: var(--ink-muted); padding: 48px 0; }
    @media (max-width: 900px) { .grid, .grid.cols-3, .grid.cols-5 { grid-template-columns: repeat(2, 1fr); gap: 16px; } .grid.m-cols-1 { grid-template-columns: 1fr; } }
</style>
@endpush
@endonce
