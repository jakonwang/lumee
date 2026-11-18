@extends('emails.layout')

@section('lang', str_replace('_', '-', app()->getLocale()))

@section('preheader'){{ $campaign->preheader }}@endsection

@section('content')
    @if ($campaign->heading)
        <h1 style="margin:0 0 16px;font-family:{{ $c['serif'] }};font-size:28px;font-weight:normal;text-align:center;">{{ $campaign->heading }}</h1>
    @endif

    {{-- Merchant Markdown rendered safely (raw HTML stripped, unsafe links removed) --}}
    <div style="font-size:15px;line-height:1.7;">{!! str_replace(['<p>', '<a '], ['<p style="margin:0 0 14px;">', '<a style="color:'.$c['primary'].';" '], $body) !!}</div>

    @if ($campaign->discount_code)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:20px;background:{{ $c['soft'] }};border:1px dashed {{ $c['primary'] }};border-radius:10px;">
            <tr><td align="center" style="padding:16px;font-size:14px;">
                {{ __('Use this code at checkout:') }}<br>
                <span style="display:inline-block;margin-top:6px;font-size:20px;font-weight:700;letter-spacing:2px;color:{{ $c['primary'] }};">{{ $campaign->discount_code }}</span>
            </td></tr>
        </table>
    @endif

    @if ($campaign->button_label && $buttonUrl)
        @include('emails.partials.button', ['href' => $buttonUrl, 'label' => $campaign->button_label])
    @endif

    @if ($products->isNotEmpty())
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:24px;">
            @foreach ($products->chunk(2) as $row)
                <tr>
                    @foreach ($row as $p)
                        <td width="50%" valign="top" style="padding:8px;">
                            <a href="{{ $p['url'] }}" style="text-decoration:none;color:{{ $c['ink'] }};">
                                @if ($p['image'])
                                    <img src="{{ $p['image'] }}" alt="{{ $p['title'] }}" width="244" style="width:100%;max-width:244px;height:auto;border-radius:10px;border:0;display:block;">
                                @endif
                                <div style="font-size:14px;margin-top:8px;">{{ $p['title'] }}</div>
                                <div style="font-size:14px;color:{{ $c['primary'] }};margin-top:2px;">
                                    {{ $money($p['price']) }}
                                    @if ($p['compare'] && $p['compare'] > $p['price'])<span style="color:{{ $c['muted'] }};text-decoration:line-through;margin-left:6px;">{{ $money($p['compare']) }}</span>@endif
                                </div>
                            </a>
                        </td>
                    @endforeach
                    @if ($row->count() === 1)<td width="50%"></td>@endif
                </tr>
            @endforeach
        </table>
    @endif
@endsection

@section('footer')
    {{ __('You’re receiving this because you subscribed to emails from :store.', ['store' => $store->name]) }}<br>
    <a href="{{ $unsubscribeUrl }}" style="color:{{ $c['muted'] }};">{{ __('Unsubscribe') }}</a> ·
    <a href="{{ $shopUrl }}" style="color:{{ $c['muted'] }};">{{ $store->name }}</a>
    @if ($openPixel)<img src="{{ $openPixel }}" width="1" height="1" alt="" style="display:block;border:0;width:1px;height:1px;">@endif
@endsection
