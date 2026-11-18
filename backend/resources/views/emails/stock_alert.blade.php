@extends('emails.layout')

@section('lang', str_replace('_', '-', app()->getLocale()))

@section('preheader'){{ __('Good news — it’s available again. Quantities may be limited.') }}@endsection

@section('content')
    <h1 style="margin:0 0 16px;font-family:{{ $c['serif'] }};font-size:28px;font-weight:normal;">{{ __('It’s back!') }}</h1>
    <p style="margin:0;">{{ __('You asked us to let you know when this was available again. Good news — it’s back in stock. Quantities may be limited.') }}</p>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:24px;border:1px solid {{ $c['border'] }};border-radius:12px;">
        <tr>
            @if ($image)
                <td width="120" style="padding:16px;"><img src="{{ $image }}" alt="" width="104" style="width:104px;height:104px;object-fit:cover;border-radius:8px;display:block;"></td>
            @endif
            <td style="padding:16px;font-size:15px;">
                <strong>{{ $product->title }}</strong>
                @if ($variantTitle)<br><span style="color:{{ $c['muted'] }};">{{ $variantTitle }}</span>@endif
                <br><span>{{ $price }}</span>
            </td>
        </tr>
    </table>
    @include('emails.partials.button', ['href' => $url, 'label' => __('Shop now')])
@endsection

@section('footer')
    {{ __('You received this email because you asked to be notified about this product at :store.', ['store' => $store->name]) }}<br>
    <a href="{{ $store->storefrontUrl() }}" style="color:{{ $c['muted'] }};">{{ $store->name }}</a>
@endsection
