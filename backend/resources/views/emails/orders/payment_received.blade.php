@extends('emails.layout')

@section('preheader'){{ __("We've received your payment for order :number.", ['number' => $order->name()]) }}@endsection

@section('content')
    <p style="margin:0 0 4px;font-size:12px;letter-spacing:2px;text-transform:uppercase;color:{{ $c['primary'] }};">{{ __('Order :number', ['number' => $order->name()]) }}</p>
    <h1 style="margin:0 0 16px;font-family:{{ $c['serif'] }};font-size:28px;font-weight:normal;">{{ __('Payment received') }}</h1>
    <p style="margin:0;">{!! __("Thank you — we've received your payment of :amount. Your order is confirmed and we're preparing it now. We'll email you again when it ships.", [
        'amount' => '<strong>'.e($money($order->total).' '.$order->currency).'</strong>',
    ]) !!}</p>

    @include('emails.partials.button', ['href' => $orderUrl, 'label' => __('View your order')])

    @include('emails.partials.summary')
@endsection

@section('footer')
    @include('emails.partials.customer-footer')
@endsection
