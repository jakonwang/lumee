@extends('emails.layout')

@php($first = $order->shipping_address['first_name'] ?? '')

@section('preheader')
    {{ $order->isPaid()
        ? __("We've received your order :number and it's being prepared.", ['number' => $order->name()])
        : __('Complete your payment to confirm order :number.', ['number' => $order->name()]) }}
@endsection

@section('content')
    <p style="margin:0 0 4px;font-size:12px;letter-spacing:2px;text-transform:uppercase;color:{{ $c['primary'] }};">{{ __('Order :number', ['number' => $order->name()]) }}</p>
    <h1 style="margin:0 0 16px;font-family:{{ $c['serif'] }};font-size:28px;font-weight:normal;color:{{ $c['ink'] }};">{{ $first ? __('Thank you, :name!', ['name' => $first]) : __('Thank you!') }}</h1>

    @if ($order->isPaid())
        <p style="margin:0;">{{ __("We've received your order and payment. We're getting it ready and will email you as soon as it ships.") }}</p>
    @else
        @php($instructions = \App\Payments\PaymentSettings::for($store)->instructions())
        <p style="margin:0 0 16px;">{{ __("We've received your order. To confirm it, please complete your payment:") }}</p>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{{ $c['soft'] }};border:1px solid {{ $c['border'] }};border-radius:10px;">
            <tr><td style="padding:16px 20px;font-size:14px;line-height:1.8;">
                <strong>{{ __('Amount:') }}</strong> {{ $money($order->total) }} {{ $order->currency }}<br>
                <strong>{{ __('Payment reference:') }}</strong> {{ $order->name() }}<br>
                @if ($instructions)
                    <div style="margin-top:8px;white-space:pre-line;">{{ $instructions }}</div>
                    <span style="color:{{ $c['muted'] }};">{{ __('Your order ships once payment is received.') }}</span>
                @else
                    <span style="color:{{ $c['muted'] }};">{{ __("Reply to this email if you need the store's payment details. Your order ships once payment is received.") }}</span>
                @endif
            </td></tr>
        </table>
    @endif

    @include('emails.partials.button', ['href' => $orderUrl, 'label' => __('View your order')])

    @include('emails.partials.summary')

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:28px;font-size:14px;line-height:1.7;">
        <tr>
            <td width="50%" style="vertical-align:top;padding-right:12px;">
                <div style="font-weight:600;margin-bottom:4px;">{{ __('Shipping address') }}</div>
                @include('emails.partials.address')
            </td>
            <td width="50%" style="vertical-align:top;">
                <div style="font-weight:600;margin-bottom:4px;">{{ __('Shipping method') }}</div>
                {{ $order->shipping_method['name'] ?? '—' }}
            </td>
        </tr>
    </table>
@endsection

@section('footer')
    @include('emails.partials.customer-footer')
@endsection
