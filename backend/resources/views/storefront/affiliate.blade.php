<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Affiliate dashboard') }} – {{ $store->name }}</title>
    <style>
        body { margin: 0; font-family: -apple-system, 'Segoe UI', Helvetica, Arial, sans-serif; color: #1C2621; background: #F7F9F8; padding: 24px 16px; }
        main { max-width: 760px; margin: 0 auto; }
        h1 { font-size: 24px; margin: 4px 0 4px; }
        .muted { color: #5C6B63; font-size: 14px; line-height: 1.6; }
        .card { background: #fff; border: 1px solid #DDE5E0; border-radius: 12px; padding: 16px 18px; margin-top: 16px; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-top: 16px; }
        .stat { background: #fff; border: 1px solid #DDE5E0; border-radius: 12px; padding: 14px; }
        .stat b { display: block; font-size: 22px; margin-top: 4px; }
        .link { display: flex; gap: 8px; margin-top: 8px; }
        .link input { flex: 1; min-width: 0; padding: 10px 12px; border: 1px solid #DDE5E0; border-radius: 8px; font-size: 14px; }
        .link button { padding: 10px 16px; border: 0; border-radius: 8px; background: #0E8A5F; color: #fff; font-weight: 600; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        td, th { text-align: left; padding: 8px 4px; border-top: 1px solid #EEF2EF; }
        th { color: #5C6B63; font-weight: 500; border-top: 0; }
        .tag { font-size: 12px; padding: 2px 8px; border-radius: 99px; background: #EAF7F1; color: #0A7350; }
        .tag.pending { background: #FFF7E6; color: #8A5A00; }
        .tag.void { background: #F1F3F2; color: #5C6B63; }
    </style>
</head>
<body>
<main>
    <div class="muted">{{ $store->name }}</div>
    <h1>{{ __('Hi :name', ['name' => $affiliate->name]) }}</h1>
    <p class="muted">{{ __('You earn :rate% on every order placed through your link (after discounts, excluding shipping and tax). Commissions are paid once the :days-day return window has passed.', ['rate' => rtrim(rtrim(number_format($affiliate->commission_rate, 2), '0'), '.'), 'days' => $settings['return_days']]) }}</p>
    @if ($affiliate->status !== 'active')
        <p class="card">{{ __('Your affiliate link is paused. Contact the store to reactivate it.') }}</p>
    @endif

    <div class="card">
        <strong>{{ __('Your link') }}</strong>
        <div class="link">
            <input id="ref" value="{{ $link }}" readonly aria-label="{{ __('Your link') }}">
            <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('ref').value); this.textContent = @js(__('Copied'))">{{ __('Copy') }}</button>
        </div>
        <p class="muted" style="margin:8px 0 0;">{{ __('Add ?ref=:code to any page of the store, too.', ['code' => $affiliate->code]) }}</p>
    </div>

    <div class="stats">
        <div class="stat"><span class="muted">{{ __('Clicks') }}</span><b>{{ $stats['clicks'] }}</b></div>
        <div class="stat"><span class="muted">{{ __('Orders') }}</span><b>{{ $stats['orders'] }}</b></div>
        <div class="stat"><span class="muted">{{ __('Pending') }}</span><b>{{ $money($stats['pending']) }}</b></div>
        <div class="stat"><span class="muted">{{ __('Paid') }}</span><b>{{ $money($stats['paid']) }}</b></div>
    </div>

    <div class="card">
        <strong>{{ __('Recent orders') }}</strong>
        @if ($commissions->isEmpty())
            <p class="muted">{{ __('No orders yet — share your link to get started.') }}</p>
        @else
            <table>
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Order value') }}</th><th>{{ __('Commission') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($commissions as $c)
                    <tr>
                        <td>{{ $c->created_at->isoFormat('ll') }}</td>
                        <td>{{ $money($c->base_amount) }}</td>
                        <td>{{ $money($c->amount) }}</td>
                        <td><span class="tag {{ $c->status }}">{{ ['pending' => __('Pending'), 'paid' => __('Paid'), 'void' => __('Cancelled')][$c->status] ?? $c->status }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
</main>
</body>
</html>
