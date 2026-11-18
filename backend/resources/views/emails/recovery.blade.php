@extends('emails.layout')

@section('lang', str_replace('_', '-', app()->getLocale()))

@section('preheader'){{ __('Your items are still waiting — complete your order whenever you are ready.') }}@endsection

@section('content')
    <h1 style="margin:0 0 16px;font-family:{{ $c['serif'] }};font-size:28px;font-weight:normal;">{{ __('You left something in your bag') }}</h1>
    <p style="margin:0;">{{ __("We saved the items from your last visit. They're still available — complete your order whenever you're ready.") }}</p>

    @if ($discountCode)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:20px;background:{{ $c['soft'] }};border:1px dashed {{ $c['primary'] }};border-radius:10px;">
            <tr><td align="center" style="padding:16px;font-size:14px;">
                {{ __('Use this code at checkout:') }}<br>
                <span style="display:inline-block;margin-top:6px;font-size:20px;font-weight:700;letter-spacing:2px;color:{{ $c['primary'] }};">{{ $discountCode }}</span>
            </td></tr>
        </table>
    @endif

    @include('emails.partials.button', ['href' => $recoverUrl, 'label' => __('Complete your purchase')])

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid {{ $c['border'] }};margin-top:24px;">
        @foreach ($cart->items as $item)
            @continue(! $item->variant || ! $item->variant->product)
            @php($image = $item->imageUrl())
            <tr>
                <td width="64" style="padding:14px 0;border-bottom:1px solid {{ $c['border'] }};vertical-align:middle;">
                    @if ($image)
                        <img src="{{ \Illuminate\Support\Str::startsWith($image, ['http://', 'https://']) ? $image : $store->storefrontUrl().$image }}" alt="" width="56" height="56" style="width:56px;height:56px;object-fit:cover;border-radius:8px;border:0;display:block;">
                    @endif
                </td>
                <td style="padding:14px 12px;border-bottom:1px solid {{ $c['border'] }};vertical-align:middle;font-size:14px;">
                    {{ $item->variant->product->title }}
                    @if ($item->variant->title && $item->variant->title !== 'Default')<br><span style="color:{{ $c['muted'] }};font-size:13px;">{{ $item->variant->title }}</span>@endif
                    <br><span style="color:{{ $c['muted'] }};font-size:13px;">{{ __('Qty') }}: {{ $item->quantity }}</span>
                </td>
                <td align="right" style="padding:14px 0;border-bottom:1px solid {{ $c['border'] }};vertical-align:middle;font-size:14px;white-space:nowrap;">{{ $money($item->lineTotal()) }}</td>
            </tr>
        @endforeach
    </table>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:12px;font-size:15px;">
        <tr><td style="padding:4px 0;font-weight:700;">{{ __('Subtotal') }}</td><td align="right" style="font-weight:700;">{{ $money($cart->subtotal()) }}</td></tr>
    </table>
@endsection

@section('footer')
    {{ __('You received this email because you started a checkout at :store. We only send this reminder once.', ['store' => $store->name]) }}<br>
    <a href="{{ $store->storefrontUrl() }}" style="color:{{ $c['muted'] }};">{{ $store->name }}</a>
@endsection
