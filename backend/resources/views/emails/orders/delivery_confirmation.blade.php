@extends('emails.layout')

@section('preheader'){{ __('Your order :number has arrived.', ['number' => $order->name()]) }}@endsection

@section('content')
    <p style="margin:0 0 4px;font-size:12px;letter-spacing:2px;text-transform:uppercase;color:{{ $c['primary'] }};">{{ __('Order :number', ['number' => $order->name()]) }}</p>
    <h1 style="margin:0 0 16px;font-family:{{ $c['serif'] }};font-size:28px;font-weight:normal;">{{ __('Your order has been delivered') }}</h1>
    <p style="margin:0 0 16px;">{{ __('Your package was delivered:date. We hope you love it!', ['date' => $order->delivered_at ? ' '.$order->delivered_at->format('F j') : '']) }}</p>
    <p style="margin:0;color:{{ $c['muted'] }};font-size:14px;">{{ __('If anything is missing or not right, just reply to this email and we’ll sort it out.') }}</p>

    @include('emails.partials.button', ['href' => $orderUrl, 'label' => __('View your order')])

    @include('emails.partials.summary')
@endsection

@section('footer')
    @include('emails.partials.customer-footer')
@endsection
