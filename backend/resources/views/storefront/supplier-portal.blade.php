{{-- Supplier portal: a private link for one supplier (no account). Standalone page, store-branded lightly. --}}
@php
    $statusLabel = ['sent' => __('New'), 'confirmed' => __('Confirmed'), 'shipped' => __('Shipped'), 'received' => __('Received'), 'cancelled' => __('Cancelled')];
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\I18n\Localization::dir() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $store->name }} · {{ __('Supplier portal') }}</title>
    <style>
        :root { --ink: #18211c; --muted: #5b6660; --border: #e3e8e5; --brand: #1f7a4d; --soft: #f3f7f5; }
        * { box-sizing: border-box; }
        body { margin: 0; font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; color: var(--ink); background: var(--soft); }
        .wrap { max-width: 900px; margin: 0 auto; padding: 32px 16px 64px; display: grid; gap: 16px; }
        .card { background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 20px; }
        h1 { margin: 0; font-size: 24px; } h2 { margin: 0 0 12px; font-size: 18px; }
        .muted { color: var(--muted); font-size: 13px; }
        .status { display: inline-block; font-size: 12px; padding: 2px 8px; border-radius: 999px; background: var(--soft); }
        .status.sent { background: #fff4e0; color: #8a5a00; } .status.shipped, .status.received { background: #e6f4ec; color: var(--brand); }
        table { width: 100%; border-collapse: collapse; font-size: 14px; margin: 8px 0; }
        th, td { text-align: start; padding: 6px; border-bottom: 1px solid var(--border); }
        td.r, th.r { text-align: end; }
        form { display: grid; gap: 10px; }
        .row { display: flex; gap: 10px; flex-wrap: wrap; align-items: end; }
        label { display: grid; gap: 4px; font-size: 13px; color: var(--muted); }
        input, textarea, select { font: inherit; padding: 8px 10px; border: 1px solid var(--border); border-radius: 8px; color: var(--ink); background: #fff; }
        textarea { min-height: 70px; }
        button { font: inherit; border: 0; border-radius: 8px; padding: 9px 16px; background: var(--brand); color: #fff; font-weight: 600; cursor: pointer; }
        button.secondary { background: #fff; color: var(--brand); border: 1px solid var(--brand); }
        .notice { background: #e6f4ec; color: var(--brand); padding: 10px 14px; border-radius: 8px; }
        .err { color: #b42318; font-size: 13px; }
        .po + .po { border-top: 1px solid var(--border); margin-top: 16px; padding-top: 16px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="muted">{{ __('Supplier portal') }}</div>
        <h1>{{ $store->name }} × {{ $supplier->name }}</h1>
        <div class="muted">{{ __('This private link is only for you. Confirm orders, share the ship date and tracking, and offer new products here.') }}</div>
    </div>
    @if (session('status'))<div class="notice">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="card err">{{ $errors->first() }}</div>@endif

    <div class="card">
        <h2>{{ __('Purchase orders') }}</h2>
        @forelse ($orders as $po)
            <div class="po">
                <div><strong>{{ $po->name() }}</strong> <span class="status {{ $po->status }}">{{ $statusLabel[$po->status] ?? $po->status }}</span>
                    <span class="muted">· {{ $po->sent_at?->toDateString() }}</span></div>
                <table>
                    <thead><tr><th>SKU</th><th>{{ __('Product') }}</th><th class="r">{{ __('Quantity') }}</th></tr></thead>
                    <tbody>@foreach ($po->items as $i)<tr><td>{{ $i['sku'] ?? '—' }}</td><td>{{ $i['title'] }}@if ($i['variant_title']) · {{ $i['variant_title'] }}@endif</td><td class="r">{{ $i['quantity'] }}</td></tr>@endforeach</tbody>
                </table>
                @if ($po->note)<div class="muted">{{ __('Note from the store:') }} {{ $po->note }}</div>@endif
                @if ($po->expected_at)<div class="muted">{{ __('Ship date:') }} {{ $po->expected_at->toDateString() }}</div>@endif
                @if ($po->tracking)<div class="muted">{{ __('Tracking:') }} {{ $po->tracking }}</div>@endif
                @if (in_array($po->status, ['sent', 'confirmed'], true))
                    <form method="post" action="/supplier/{{ $supplier->token }}/orders/{{ $po->id }}" style="margin-top:10px">
                        @csrf
                        <div class="row">
                            <label>{{ __('Ship date') }}<input type="date" name="expected_at" value="{{ $po->expected_at?->toDateString() }}"></label>
                            <label>{{ __('Tracking number') }}<input name="tracking" value="{{ $po->tracking }}"></label>
                        </div>
                        <label>{{ __('Note (optional)') }}<textarea name="note">{{ $po->supplier_note }}</textarea></label>
                        <div class="row">
                            @if ($po->status === 'sent')<button name="action" value="confirm">{{ __('Confirm order') }}</button>@endif
                            <button name="action" value="ship" class="{{ $po->status === 'sent' ? 'secondary' : '' }}">{{ __('Mark as shipped') }}</button>
                        </div>
                    </form>
                @endif
            </div>
        @empty
            <div class="muted">{{ __('No orders yet.') }}</div>
        @endforelse
    </div>

    <div class="card">
        <h2>{{ __('Offer a new product') }}</h2>
        <form method="post" action="/supplier/{{ $supplier->token }}/products">
            @csrf
            <label>{{ __('Product name') }}<input name="title" required maxlength="200" value="{{ old('title') }}"></label>
            <div class="row">
                <label>{{ __('Unit price') }}<input name="price" type="number" step="0.01" min="0" required value="{{ old('price') }}"></label>
                <label>{{ __('Currency') }}<select name="currency"><option>CNY</option><option>USD</option><option>EUR</option><option>HKD</option></select></label>
                <label>{{ __('Minimum order') }}<input name="moq" type="number" min="1" value="{{ old('moq') }}"></label>
            </div>
            <label>{{ __('Product page (optional, e.g. 1688)') }}<input name="url" type="url" value="{{ old('url') }}"></label>
            <label>{{ __('Image links (one per line)') }}<textarea name="images">{{ old('images') }}</textarea></label>
            <label>{{ __('Description: material, sizes, packing') }}<textarea name="description">{{ old('description') }}</textarea></label>
            <div><button type="submit">{{ __('Send to :store', ['store' => $store->name]) }}</button></div>
        </form>
    </div>
</div>
</body>
</html>
