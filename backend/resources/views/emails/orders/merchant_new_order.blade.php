@extends('emails.layout')

@section('lang', str_replace('_', '-', app()->getLocale()))

@php($a = $order->shipping_address)
@php($labels = ['subtotal' => __('小计'), 'shipping' => __('运费'), 'free' => __('免运费'), 'total' => __('合计'), 'qty' => __('数量'), 'discount' => __('折扣'), 'promotion' => __('活动'), 'refunded' => __('已退款')])
@php($gateways = ['stripe' => __('Stripe 信用卡'), 'manual' => __('银行转账'), 'paypal' => 'PayPal', 'airwallex' => 'Airwallex', 'razorpay' => 'Razorpay', 'test' => __('测试支付')])

@section('preheader'){{ __(':p0 下单了 :p1', ['p0' => trim(($a['first_name'] ?? '').' '.($a['last_name'] ?? '')), 'p1' => $money($order->total)]) }}@endsection

@section('content')
    <p style="margin:0 0 4px;font-size:12px;letter-spacing:2px;color:{{ $c['primary'] }};">{{ __('新订单') }}</p>
    <h1 style="margin:0 0 16px;font-family:{{ $c['serif'] }};font-size:28px;font-weight:normal;">{{ $order->name() }} · {{ $money($order->total) }}</h1>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{{ $c['soft'] }};border:1px solid {{ $c['border'] }};border-radius:10px;font-size:14px;line-height:1.9;">
        <tr><td style="padding:16px 20px;">
            <strong>{{ __('客户：') }}</strong>{{ $a['first_name'] ?? '' }} {{ $a['last_name'] ?? '' }} ({{ $order->email }})<br>
            <strong>{{ __('付款状态：') }}</strong>{{ $order->isPaid() ? __('已付款') : __('待付款') }} · {{ $gateways[$order->payment_gateway] ?? $order->payment_gateway }}<br>
            <strong>{{ __('配送：') }}</strong>{{ $order->shipping_method['name'] ?? '—' }} → {{ \App\Support\Countries::all()[$a['country'] ?? ''] ?? '' }}
            @if ($order->note)<br><strong>{{ __('买家备注：') }}</strong>{{ $order->note }}@endif
        </td></tr>
    </table>

    @if (in_array($order->risk_level, ['medium', 'high'], true))
        <p style="margin:16px 0 0;padding:12px 16px;border-radius:10px;font-size:14px;line-height:1.7;background:#FFF7E6;border:1px solid #F5D9A8;color:#8A5A00;">
            <strong>{{ $order->risk_level === 'high' ? __('高风险订单') : __('中风险订单') }}（{{ __('风险分 :p0', ['p0' => $order->risk_score]) }}）</strong><br>
            @foreach (array_slice($order->risk_reasons ?? [], 0, 4) as $r)· {{ \App\Risk\RiskAnalyzer::describe($r, $order->currency) }}<br>@endforeach
            @if ($order->risk_hold){{ __('订单已暂停发货，请在后台核实后再处理。') }}@endif
        </p>
    @endif

    @include('emails.partials.button', ['href' => $adminUrl, 'label' => $order->isPaid() ? __('去后台发货') : __('查看订单')])

    @include('emails.partials.summary', ['labels' => $labels])
@endsection

@section('footer')
    {{ __('这封邮件发送给 :p0 的店铺员工。可以在后台「设置 → 邮件通知」中关闭。', ['p0' => $store->name]) }}
@endsection
