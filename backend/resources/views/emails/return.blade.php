@extends('emails.layout')

@section('lang', str_replace('_', '-', app()->getLocale()))

@if ($to === 'merchant')
    @section('preheader'){{ __('买家申请退货，请在后台处理。') }}@endsection
@else
    @section('preheader'){{ $r->status === 'approved' ? __('Your return has been approved.') : __('There is an update on your return request.') }}@endsection
@endif

@section('content')
    @if ($to === 'merchant')
        <h1 style="margin:0 0 16px;font-family:{{ $c['serif'] }};font-size:26px;font-weight:normal;">{{ __('订单 :p0 收到退货申请', ['p0' => $order->name()]) }}</h1>
        <p style="margin:0;">{{ __('买家 :p0 申请退货，原因：:p1', ['p0' => $order->email, 'p1' => $reason]) }}</p>
        @if ($r->customer_note)
            <p style="margin:12px 0 0;padding:12px 16px;border-left:3px solid {{ $c['primary'] }};background:{{ $c['soft'] }};white-space:pre-line;">{{ $r->customer_note }}</p>
        @endif
    @elseif ($r->status === 'approved')
        <h1 style="margin:0 0 16px;font-family:{{ $c['serif'] }};font-size:26px;font-weight:normal;">{{ __('Your return is approved') }}</h1>
        <p style="margin:0;">{{ __('Please send the items below back to us. We will refund you once we receive them.') }}</p>
        @if ($r->return_instructions)
            <p style="margin:16px 0 0;padding:12px 16px;border-left:3px solid {{ $c['primary'] }};background:{{ $c['soft'] }};white-space:pre-line;">{{ $r->return_instructions }}</p>
        @endif
    @else
        <h1 style="margin:0 0 16px;font-family:{{ $c['serif'] }};font-size:26px;font-weight:normal;">{{ __('Update on your return request') }}</h1>
        <p style="margin:0;">{{ __("We're sorry — we can't accept this return.") }}</p>
        @if ($r->merchant_note)
            <p style="margin:16px 0 0;padding:12px 16px;border-left:3px solid {{ $c['primary'] }};background:{{ $c['soft'] }};white-space:pre-line;">{{ $r->merchant_note }}</p>
        @endif
    @endif

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid {{ $c['border'] }};margin-top:24px;">
        @foreach ($items as $i)
            <tr>
                <td style="padding:12px 0;border-bottom:1px solid {{ $c['border'] }};font-size:14px;">
                    {{ $i['title'] }}@if ($i['variant'])<br><span style="color:{{ $c['muted'] }};font-size:13px;">{{ $i['variant'] }}</span>@endif
                </td>
                <td align="right" style="padding:12px 0;border-bottom:1px solid {{ $c['border'] }};font-size:14px;">× {{ $i['quantity'] }}</td>
            </tr>
        @endforeach
    </table>

    @include('emails.partials.button', $to === 'merchant'
        ? ['href' => $adminUrl, 'label' => __('在后台处理')]
        : ['href' => $orderUrl, 'label' => __('View your order')])
@endsection

@section('footer')
    <a href="{{ $store->storefrontUrl() }}" style="color:{{ $c['muted'] }};">{{ $store->name }}</a>
@endsection
