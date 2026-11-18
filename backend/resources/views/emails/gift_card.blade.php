@extends('emails.layout')

@section('lang', str_replace('_', '-', app()->getLocale()))

@section('preheader'){{ __('A gift card worth :amount to spend at :store.', ['amount' => $money($card->balance), 'store' => $store->name]) }}@endsection

@section('content')
    <h1 style="margin:0 0 16px;font-family:{{ $c['serif'] }};font-size:28px;font-weight:normal;">{{ __("You've received a gift card") }}</h1>
    @if ($card->recipient_name)
        <p style="margin:0 0 8px;">{{ __('Hi :name,', ['name' => $card->recipient_name]) }}</p>
    @endif
    <p style="margin:0;">{{ __('Here is your gift card for :store. Enter the code at checkout to use it — any balance left stays on the card.', ['store' => $store->name]) }}</p>
    @if ($card->message)
        <p style="margin:16px 0 0;padding:12px 16px;border-left:3px solid {{ $c['primary'] }};background:{{ $c['soft'] }};white-space:pre-line;">{{ $card->message }}</p>
    @endif

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:24px;background:{{ $c['soft'] }};border:1px dashed {{ $c['primary'] }};border-radius:12px;">
        <tr><td align="center" style="padding:22px 16px;">
            <div style="font-size:30px;font-weight:700;color:{{ $c['primary'] }};">{{ $money($card->balance) }}</div>
            <div style="margin-top:10px;font-size:20px;font-weight:700;letter-spacing:3px;font-family:Menlo,Consolas,monospace;">{{ $card->formattedCode() }}</div>
            @if ($card->expires_on)
                <div style="margin-top:8px;font-size:13px;color:{{ $c['muted'] }};">{{ __('Expires on :date', ['date' => $card->expires_on->toDateString()]) }}</div>
            @endif
        </td></tr>
    </table>

    @include('emails.partials.button', ['href' => $store->storefrontUrl(), 'label' => __('Start shopping')])
@endsection

@section('footer')
    {{ __('Keep this email — the code works like cash at :store.', ['store' => $store->name]) }}<br>
    <a href="{{ $store->storefrontUrl() }}" style="color:{{ $c['muted'] }};">{{ $store->name }}</a>
@endsection
