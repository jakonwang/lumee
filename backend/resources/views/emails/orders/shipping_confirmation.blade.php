@extends('emails.layout')

@php($trackUrl = $order->tracking_number ? 'https://t.17track.net/en#nums='.rawurlencode($order->tracking_number) : null)

@section('preheader'){{ __('Good news — order :number has shipped.', ['number' => $order->name()]) }}@endsection

@section('content')
    <p style="margin:0 0 4px;font-size:12px;letter-spacing:2px;text-transform:uppercase;color:{{ $c['primary'] }};">{{ __('Order :number', ['number' => $order->name()]) }}</p>
    <h1 style="margin:0 0 16px;font-family:{{ $c['serif'] }};font-size:28px;font-weight:normal;">{{ __('Your order is on its way') }}</h1>
    <p style="margin:0 0 16px;">{{ __('Good news — your order has shipped and is heading to you.') }}</p>

    @if ($order->tracking_number)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{{ $c['soft'] }};border:1px solid {{ $c['border'] }};border-radius:10px;">
            <tr><td style="padding:16px 20px;font-size:14px;line-height:1.8;">
                @if ($order->tracking_company)<strong>{{ __('Carrier:') }}</strong> {{ $order->tracking_company }}<br>@endif
                <strong>{{ __('Tracking number:') }}</strong> {{ $order->tracking_number }}
            </td></tr>
        </table>
        @include('emails.partials.button', ['href' => $trackUrl, 'label' => __('Track your package')])
    @else
        @include('emails.partials.button', ['href' => $orderUrl, 'label' => __('View your order')])
    @endif

    @include('emails.partials.summary')

    <div style="margin-top:28px;font-size:14px;line-height:1.7;">
        <div style="font-weight:600;margin-bottom:4px;">{{ __('Shipping to') }}</div>
        @include('emails.partials.address')
    </div>
@endsection

@section('footer')
    @include('emails.partials.customer-footer')
@endsection
