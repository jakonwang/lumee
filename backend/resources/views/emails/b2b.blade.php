@extends('emails.layout')

@section('lang', str_replace('_', '-', app()->getLocale()))

@section('content')
    @php
        $h = 'margin:0 0 16px;font-family:'.$c['serif'].';font-size:26px;font-weight:normal;';
        $box = 'margin:16px 0 0;padding:12px 16px;border-left:3px solid '.$c['primary'].';background:'.$c['soft'].';white-space:pre-line;';
    @endphp

    @if ($type === 'application_received')
        <h1 style="{{ $h }}">{{ __('新的批发申请') }}</h1>
        <p style="margin:0;">{{ $application->company_name }} · {{ $application->contact_name }} · {{ $application->email }}</p>
        @if ($application->message)<p style="{{ $box }}">{{ $application->message }}</p>@endif
        @include('emails.partials.button', ['href' => $adminUrl, 'label' => __('在后台处理')])

    @elseif ($type === 'application_approved')
        <h1 style="{{ $h }}">{{ __('Welcome to :store wholesale', ['store' => $store->name]) }}</h1>
        <p style="margin:0;">{{ __('Your application for :company has been approved. Sign in to see your wholesale prices and order.', ['company' => $application->company_name]) }}</p>
        @include('emails.partials.button', ['href' => $link, 'label' => $isNew ? __('Set your password') : __('Sign in')])

    @elseif ($type === 'application_rejected')
        <h1 style="{{ $h }}">{{ __('About your wholesale application') }}</h1>
        <p style="margin:0;">{{ __('Thank you for your interest. We’re not able to open a wholesale account for :company at this time.', ['company' => $application->company_name]) }}</p>
        @if ($reason ?? null)<p style="{{ $box }}">{{ $reason }}</p>@endif

    @elseif (in_array($type, ['quote_requested', 'quote_sent', 'quote_accepted'], true))
        <h1 style="{{ $h }}">
            @if ($type === 'quote_requested'){{ __('收到询价单 :p0', ['p0' => $quote->name()]) }}
            @elseif ($type === 'quote_accepted'){{ __('报价单 :p0 已被接受', ['p0' => $quote->name()]) }}
            @else{{ __('Your quote :quote is ready', ['quote' => $quote->name()]) }}@endif
        </h1>
        @if ($type === 'quote_sent')
            <p style="margin:0;">{{ __('Review the prices below and accept the quote to place your order.') }}@if ($quote->expires_on) {{ __('Valid until :date.', ['date' => $quote->expires_on->toDateString()]) }}@endif</p>
            @if ($quote->merchant_note)<p style="{{ $box }}">{{ $quote->merchant_note }}</p>@endif
        @elseif ($type === 'quote_requested' && $quote->buyer_note)
            <p style="{{ $box }}">{{ $quote->buyer_note }}</p>
        @endif
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid {{ $c['border'] }};margin-top:20px;">
            @foreach ($quote->items as $i)
                <tr>
                    <td style="padding:10px 0;border-bottom:1px solid {{ $c['border'] }};font-size:14px;">{{ $i['title'] }}@if ($i['variant_title'])<br><span style="color:{{ $c['muted'] }};font-size:13px;">{{ $i['variant_title'] }}</span>@endif</td>
                    <td align="right" style="padding:10px 0;border-bottom:1px solid {{ $c['border'] }};font-size:14px;white-space:nowrap;">{{ $i['quantity'] }} × {{ $money((int) ($i['price'] ?? $i['list_price'])) }}</td>
                </tr>
            @endforeach
        </table>
        @include('emails.partials.button', $type === 'quote_sent' ? ['href' => $quoteUrl, 'label' => __('View quote')] : ['href' => $adminUrl, 'label' => __('在后台处理')])

    @elseif ($type === 'payment_overdue')
        <h1 style="{{ $h }}">{{ __('Payment reminder') }}</h1>
        <p style="margin:0;">{{ __('Payment of :amount for order :order was due on :date. Please arrange payment at your earliest convenience.', ['amount' => $money($order->total), 'order' => $order->name(), 'date' => $order->payment_due_at?->toDateString()]) }}</p>
        @if ($order->po_number)<p style="margin:8px 0 0;color:{{ $c['muted'] }};">PO: {{ $order->po_number }}</p>@endif
    @endif
@endsection

@section('footer')
    <a href="{{ $store->storefrontUrl() }}" style="color:{{ $c['muted'] }};">{{ $store->name }}</a>
@endsection
