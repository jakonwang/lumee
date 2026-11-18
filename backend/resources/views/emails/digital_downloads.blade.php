@extends('emails.layout')

@section('lang', str_replace('_', '-', app()->getLocale()))

@section('preheader'){{ __('Your files are ready to download.') }}@endsection

@section('content')
    <h1 style="margin:0 0 16px;font-family:{{ $c['serif'] }};font-size:28px;font-weight:normal;">{{ __('Your downloads are ready') }}</h1>
    <p style="margin:0 0 20px;">{{ __('Thanks for your order :order. Download your files below — each link works :count times and expires in :days days.', ['order' => $order->name(), 'count' => $max, 'days' => $days]) }}</p>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid {{ $c['border'] }};border-radius:12px;">
        @foreach ($links as $l)
            <tr>
                <td style="padding:14px 16px;font-size:15px;{{ $loop->first ? '' : 'border-top:1px solid '.$c['border'].';' }}">
                    <a href="{{ $l['url'] }}" style="color:{{ $c['primary'] }};font-weight:600;">{{ $l['filename'] }}</a>
                </td>
            </tr>
        @endforeach
    </table>
    @include('emails.partials.button', ['href' => $orderUrl, 'label' => __('View your order')])
@endsection

@section('footer')
    <a href="{{ $store->storefrontUrl() }}" style="color:{{ $c['muted'] }};">{{ $store->name }}</a>
@endsection
