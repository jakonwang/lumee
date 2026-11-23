@extends('emails.layout')

@section('preheader'){{ __('Your items are reserved — finish paying to complete your order.') }}@endsection

@section('content')
    <p style="margin:0 0 4px;font-size:12px;letter-spacing:2px;text-transform:uppercase;color:{{ $c['primary'] }};">{{ __('Order :number', ['number' => $order->name()]) }}</p>
    <h1 style="margin:0 0 16px;font-family:{{ $c['serif'] }};font-size:28px;font-weight:normal;">{{ __('Your order is waiting for payment') }}</h1>
    <p style="margin:0 0 16px;">{{ __('It looks like the payment wasn’t finished. We’re holding your items for you — complete your payment within 24 hours of ordering to keep them.') }}</p>
    @include('emails.partials.button', ['href' => $orderUrl, 'label' => __('Complete payment')])
    <p style="margin:16px 0 0;font-size:13px;color:{{ $c['muted'] }};">{{ __('Already paid? Then you can ignore this email — your confirmation is on its way.') }}</p>

    @include('emails.partials.summary')
    <p style="margin:24px 0 0;font-size:14px;color:{{ $c['muted'] }};">{{ __('Questions? Just reply to this email.') }}</p>
@endsection

@section('footer')
    @include('emails.partials.customer-footer')
@endsection
