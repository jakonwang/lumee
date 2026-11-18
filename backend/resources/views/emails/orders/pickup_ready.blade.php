@extends('emails.layout')

@php($pickup = $order->shipping_method['pickup'] ?? [])

@section('preheader'){{ __('Order :number is ready for pickup.', ['number' => $order->name()]) }}@endsection

@section('content')
    <p style="margin:0 0 4px;font-size:12px;letter-spacing:2px;text-transform:uppercase;color:{{ $c['primary'] }};">{{ __('Order :number', ['number' => $order->name()]) }}</p>
    <h1 style="margin:0 0 16px;font-family:{{ $c['serif'] }};font-size:28px;font-weight:normal;">{{ __('Your order is ready for pickup') }}</h1>
    <p style="margin:0 0 16px;">{{ __('Good news — your order is packed and waiting for you. Please bring your order number when you come.') }}</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{{ $c['soft'] }};border:1px solid {{ $c['border'] }};border-radius:10px;">
        <tr><td style="padding:16px 20px;font-size:14px;line-height:1.8;">
            <strong>{{ $pickup['name'] ?? '' }}</strong><br>
            {{ $pickup['address'] ?? '' }}
            @if (! empty($pickup['hours']))<br>{{ $pickup['hours'] }}@endif
            @if (! empty($pickup['instructions']))<br><span style="color:{{ $c['muted'] }};">{{ $pickup['instructions'] }}</span>@endif
        </td></tr>
    </table>
    @include('emails.partials.button', ['href' => $orderUrl, 'label' => __('View your order')])

    @include('emails.partials.summary')
@endsection

@section('footer')
    @include('emails.partials.customer-footer')
@endsection
