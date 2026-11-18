@extends($theme->view('layout'))

@section('title', __('Quote :number', ['number' => $quote->name()]).' · '.$store->name)
@section('noindex', '1')

@push('head')
<style>
    .qt { max-width: 760px; padding-block: 48px 80px; }
    .qt h1 { font-size: clamp(28px, 4vw, 38px); margin: 0 0 6px; }
    .qt-status { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 13px; background: var(--rose-gold-50); border: 1px solid var(--border); }
    .qt-card { border: 1px solid var(--border); border-radius: var(--radius-card, 12px); padding: 8px 20px; margin-top: 20px; }
    .qt-row { display: flex; justify-content: space-between; gap: 12px; padding: 12px 0; font-size: 15px; }
    .qt-row + .qt-row { border-top: 1px solid var(--border); }
    .qt-muted { color: var(--ink-muted); font-size: 14px; }
    .qt-total { font-weight: 600; font-size: 17px; }
    .qt-note { margin-top: 16px; padding: 12px 14px; border-left: 3px solid var(--rose-gold-500); background: var(--rose-gold-50); white-space: pre-line; font-size: 14px; }
    .qt-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-top: 24px; }
    .qt-notice { margin: 16px 0 0; padding: 12px 14px; border-radius: 8px; background: var(--rose-gold-50); border: 1px solid var(--border); font-size: 14px; }
</style>
@endpush

@section('content')
    @php
        $m = fn ($v) => \App\Support\Money::display($v, $store->currency);
        $label = ['requested' => __('Waiting for prices'), 'sent' => __('Ready to accept'), 'accepted' => __('Accepted'), 'declined' => __('Declined')][$quote->status] ?? $quote->status;
        $priced = in_array($quote->status, ['sent', 'accepted'], true);
        $total = $quote->subtotal() + (int) $quote->shipping_price;
    @endphp
    <section class="wrap qt">
        <div class="eyebrow">{{ __('Quote') }}</div>
        <h1 class="display">{{ $quote->name() }}</h1>
        <span class="qt-status">{{ $quote->status === 'sent' && $quote->isExpired() ? __('Expired') : $label }}</span>
        @if (session('status'))<div class="qt-notice" role="status">{{ session('status') }}</div>@endif

        <div class="qt-card">
            @foreach ($quote->items as $i)
                <div class="qt-row">
                    <span>{{ $i['title'] }}@if ($i['variant_title'])<span class="qt-muted"> · {{ $i['variant_title'] }}</span>@endif<br><span class="qt-muted">{{ __('Qty') }}: {{ $i['quantity'] }}</span></span>
                    <span>
                        @if ($priced)
                            {{ $m((int) $i['price'] * $i['quantity']) }}
                            @if ((int) $i['price'] < (int) $i['list_price'])<br><s class="qt-muted">{{ $m((int) $i['list_price'] * $i['quantity']) }}</s>@endif
                        @else
                            <span class="qt-muted">{{ $m((int) $i['list_price'] * $i['quantity']) }}</span>
                        @endif
                    </span>
                </div>
            @endforeach
            @if ($priced)
                <div class="qt-row"><span>{{ __('Shipping') }}</span><span>{{ $quote->shipping_price === null ? __('Calculated later') : ($quote->shipping_price === 0 ? __('Free') : $m($quote->shipping_price)) }}</span></div>
                <div class="qt-row qt-total"><span>{{ __('Total') }}</span><span>{{ $m($total) }}</span></div>
            @endif
        </div>
        @if ($quote->status === 'sent' && $quote->expires_on)<p class="qt-muted">{{ __('Valid until :date.', ['date' => $quote->expires_on->toDateString()]) }} {{ __('Taxes are added when the order is placed.') }}</p>@endif
        @if ($quote->merchant_note && $priced)<div class="qt-note">{{ $quote->merchant_note }}</div>@endif

        @if ($quote->status === 'sent' && ! $quote->isExpired())
            <form method="post" action="/quotes/{{ $quote->token }}/accept" class="qt-actions">
                @csrf
                <input class="field" name="po_number" maxlength="60" placeholder="{{ __('PO number (optional)') }}" style="max-width:220px;">
                <button class="btn" type="submit">{{ __('Accept quote and place order') }}</button>
            </form>
            <form method="post" action="/quotes/{{ $quote->token }}/decline" class="qt-actions" style="margin-top:8px;">
                @csrf
                <button class="link-btn" type="submit">{{ __('Decline') }}</button>
            </form>
        @elseif ($quote->status === 'accepted' && $order)
            <p class="qt-actions"><a class="btn" href="/orders/{{ $order->token }}">{{ __('View order :number', ['number' => $order->name()]) }}</a></p>
        @endif
    </section>
@endsection
