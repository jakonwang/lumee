@extends('emails.layout')

@section('preheader'){{ __('Your order :number has been cancelled.', ['number' => $order->name()]) }}@endsection

@section('content')
    <p style="margin:0 0 4px;font-size:12px;letter-spacing:2px;text-transform:uppercase;color:{{ $c['primary'] }};">{{ __('Order :number', ['number' => $order->name()]) }}</p>
    <h1 style="margin:0 0 16px;font-family:{{ $c['serif'] }};font-size:28px;font-weight:normal;">{{ __('Your order has been cancelled') }}</h1>
    <p style="margin:0 0 16px;">
        @switch($order->cancel_reason)
            @case('customer') {{ __("As requested, we've cancelled your order.") }} @break
            @case('inventory') {{ __("We're sorry — some items in your order are no longer available, so we've had to cancel it.") }} @break
            @case('declined') {{ __("Your payment couldn't be completed, so the order has been cancelled.") }} @break
            @default {{ __("We've cancelled your order.") }}
        @endswitch
    </p>
    @if ($order->refunded_total > 0)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{{ $c['soft'] }};border:1px solid {{ $c['border'] }};border-radius:10px;">
            <tr><td style="padding:16px 20px;font-size:14px;line-height:1.7;">
                <strong>{{ __('Refund: :amount', ['amount' => $money($order->refunded_total).' '.$order->currency]) }}</strong><br>
                {{ in_array($order->payment_gateway, ['stripe', 'paypal', 'airwallex', 'razorpay'], true)
                    ? __('The refund has been issued to your original payment method. It usually appears within 5–10 business days.')
                    : __('We will return the money using the same method you paid with.') }}
            </td></tr>
        </table>
    @elseif ($order->isPaid())
        <p style="margin:0 0 16px;">{{ __("If you've been charged, we'll be in touch about your refund.") }}</p>
    @else
        <p style="margin:0 0 16px;">{{ __('You have not been charged for this order.') }}</p>
    @endif

    @include('emails.partials.summary')
    <p style="margin:24px 0 0;font-size:14px;color:{{ $c['muted'] }};">{{ __('Questions? Just reply to this email.') }}</p>
@endsection

@section('footer')
    @include('emails.partials.customer-footer')
@endsection
