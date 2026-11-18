@extends('emails.layout')

@section('lang', str_replace('_', '-', app()->getLocale()))

@section('preheader'){{ $interval }}@endsection

@section('content')
    @if ($type === 'started')
        <h1 style="margin:0 0 16px;font-family:{{ $c['serif'] }};font-size:26px;font-weight:normal;">{{ __('Your subscription has started') }}</h1>
        <p style="margin:0;">{{ __('Thanks for subscribing! Your next delivery is scheduled for :date.', ['date' => $s->next_billing_at?->locale(app()->getLocale())->isoFormat('LL')]) }}</p>
    @elseif ($type === 'renewal_failed')
        <h1 style="margin:0 0 16px;font-family:{{ $c['serif'] }};font-size:26px;font-weight:normal;">{{ __('We couldn’t renew your subscription') }}</h1>
        <p style="margin:0;">{{ __('The payment for your next delivery didn’t go through. We’ll try again on :date.', ['date' => $s->next_billing_at?->locale(app()->getLocale())->isoFormat('LL')]) }}</p>
    @else
        <h1 style="margin:0 0 16px;font-family:{{ $c['serif'] }};font-size:26px;font-weight:normal;">{{ __('Your subscription has been cancelled') }}</h1>
        <p style="margin:0;">{{ __('There will be no more deliveries. You can always order again from our store.') }}</p>
    @endif

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid {{ $c['border'] }};margin-top:24px;">
        @foreach ($s->items as $i)
            <tr>
                <td style="padding:12px 0;border-bottom:1px solid {{ $c['border'] }};font-size:14px;">{{ $i['title'] }}@if (! empty($i['variant_title']))<br><span style="color:{{ $c['muted'] }};font-size:13px;">{{ $i['variant_title'] }}</span>@endif</td>
                <td align="right" style="padding:12px 0;border-bottom:1px solid {{ $c['border'] }};font-size:14px;">× {{ $i['quantity'] }}</td>
            </tr>
        @endforeach
    </table>
    <p style="margin:12px 0 0;color:{{ $c['muted'] }};font-size:13px;">{{ $interval }}</p>

    @if ($type !== 'cancelled')
        @include('emails.partials.button', ['href' => $manageUrl, 'label' => __('Manage subscription')])
    @endif
@endsection

@section('footer')
    <a href="{{ $store->storefrontUrl() }}" style="color:{{ $c['muted'] }};">{{ $store->name }}</a>
@endsection
