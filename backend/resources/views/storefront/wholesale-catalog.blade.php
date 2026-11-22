{{-- Wholesale catalog link (shared with a prospect or distributor). Standalone page: prints well, works without the theme. --}}
@php
    $money = fn (?int $v) => $v === null ? '—' : \App\Support\Money::display($v, $data['currency']);
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\I18n\Localization::dir() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Wholesale catalog') }} · {{ $store->name }}</title>
    <style>
        :root { --ink: #18211c; --muted: #5b6660; --border: #e3e8e5; --brand: #1f7a4d; --soft: #f3f7f5; }
        * { box-sizing: border-box; }
        body { margin: 0; font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; color: var(--ink); background: #fff; }
        .wrap { max-width: 1100px; margin: 0 auto; padding: 32px 16px 64px; }
        header { display: flex; justify-content: space-between; gap: 16px; align-items: flex-end; flex-wrap: wrap; border-bottom: 2px solid var(--brand); padding-bottom: 16px; margin-bottom: 20px; }
        h1 { margin: 0; font-size: 28px; }
        .muted { color: var(--muted); font-size: 13px; }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .actions a { color: var(--brand); border: 1px solid var(--border); border-radius: 999px; padding: 6px 14px; text-decoration: none; font-size: 13px; }
        .product { display: grid; grid-template-columns: 88px 1fr; gap: 16px; padding: 16px 0; border-bottom: 1px solid var(--border); break-inside: avoid; }
        .product img { width: 88px; height: 88px; object-fit: cover; border-radius: 8px; background: var(--soft); }
        .product h2 { font-size: 16px; margin: 0 0 4px; }
        .product h2 a { color: inherit; text-decoration: none; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 6px; }
        th { text-align: start; color: var(--muted); font-weight: 500; padding: 4px 6px; border-bottom: 1px solid var(--border); white-space: nowrap; }
        td { padding: 5px 6px; border-bottom: 1px solid var(--soft); font-variant-numeric: tabular-nums; }
        td.r, th.r { text-align: end; }
        .price { font-weight: 700; color: var(--brand); }
        .retail { color: var(--muted); text-decoration: line-through; }
        .table-wrap { overflow-x: auto; }
        footer { margin-top: 28px; font-size: 13px; color: var(--muted); }
        @media print { .actions { display: none; } }
        @media (max-width: 600px) { .product { grid-template-columns: 64px 1fr; } .product img { width: 64px; height: 64px; } }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <div>
            <div class="muted">{{ __('Wholesale catalog') }}{{ $data['market'] ? ' · '.$data['market'] : '' }}</div>
            <h1>{{ $store->name }}</h1>
            <div class="muted">{{ __(':count products · prices in :currency, excluding shipping and taxes', ['count' => count($data['products']), 'currency' => $data['currency']]) }}</div>
        </div>
        <div class="actions">
            <a href="{{ $csv }}">{{ __('Download CSV') }}</a>
            <a href="{{ $json }}">JSON</a>
            <a href="/wholesale">{{ __('Apply for a wholesale account') }}</a>
        </div>
    </header>

    @foreach ($data['products'] as $p)
        <div class="product">
            @if ($p['images'])<img src="{{ $p['images'][0] }}" alt="" loading="lazy">@else<div></div>@endif
            <div>
                <h2><a href="{{ $p['url'] }}">{{ $p['title'] }}</a></h2>
                @if ($p['product_type'])<div class="muted">{{ $p['product_type'] }}</div>@endif
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>SKU</th><th>{{ __('Option') }}</th><th class="r">{{ __('Retail') }}</th><th class="r">{{ __('Wholesale') }}</th><th class="r">{{ __('Min. order') }}</th>@if ($catalog->show_stock)<th class="r">{{ __('In stock') }}</th>@endif</tr></thead>
                        <tbody>
                        @foreach ($p['variants'] as $v)
                            <tr>
                                <td>{{ $v['sku'] ?? '—' }}</td>
                                <td>{{ $v['title'] ?? '—' }}</td>
                                <td class="r">@if ($v['price'] < $v['retail_price'])<span class="retail">{{ $money($v['retail_price']) }}</span>@else{{ $money($v['retail_price']) }}@endif</td>
                                <td class="r price">{{ $money($v['price']) }}@if (! empty($v['tiers']))<div class="muted">@foreach ($v['tiers'] as $t){{ $t['min'] }}+ {{ $money($t['price']) }}@if (! $loop->last) · @endif @endforeach</div>@endif</td>
                                <td class="r">{{ $v['min_qty'] }}@if ($v['step'] > 1) <span class="muted">(×{{ $v['step'] }})</span>@endif</td>
                                @if ($catalog->show_stock)<td class="r">{{ $v['stock'] ?? '—' }}</td>@endif
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endforeach

    <footer>{{ __('Prices updated :date. Contact us for larger quantities or a quote.', ['date' => \Illuminate\Support\Carbon::parse($data['generated_at'])->toDateString()]) }}</footer>
</div>
</body>
</html>
