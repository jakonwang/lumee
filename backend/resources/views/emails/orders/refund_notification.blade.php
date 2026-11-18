@extends('emails.layout')

@php($refund = $order->relationLoaded('refunds') ? $order->refunds->first() : $order->refunds()->first())

@section('preheader'){{ __('A refund for order :number has been issued.', ['number' => $order->name()]) }}@endsection

@section('content')
    <p style="margin:0 0 4px;font-size:12px;letter-spacing:2px;text-transform:uppercase;color:{{ $c['primary'] }};">{{ __('Order :number', ['number' => $order->name()]) }}</p>
    <h1 style="margin:0 0 16px;font-family:{{ $c['serif'] }};font-size:28px;font-weight:normal;">{{ __('Your refund is on its way') }}</h1>
    <p style="margin:0 0 16px;">
        {!! __("We've issued a refund of :amount for your order.", ['amount' => '<strong>'.e($money($refund?->amount ?? $order->refunded_total).' '.$order->currency).'</strong>']) !!}
        {{ in_array($order->payment_gateway, ['stripe', 'paypal', 'airwallex', 'razorpay'], true)
            ? __('It goes back to your original payment method and usually appears within 5–10 business days.')
            : __('We will return the money using the same method you paid with.') }}
    </p>
    @if ($refund?->reason)
        <p style="margin:0 0 16px;color:{{ $c['muted'] }};font-size:14px;">{{ __('Note: :reason', ['reason' => $refund->reason]) }}</p>
    @endif

    @include('emails.partials.button', ['href' => $orderUrl, 'label' => __('View your order')])
    @include('emails.partials.summary')
@endsection

@section('footer')
    @include('emails.partials.customer-footer')
@endsection
