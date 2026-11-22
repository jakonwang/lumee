{{-- Order lines + totals. Expects $order, $money, $c, $labels (optional, for Chinese merchant email) --}}
@php($l = $labels ?? ['subtotal' => __('Subtotal'), 'shipping' => __('Shipping'), 'free' => __('Free'), 'total' => __('Total'), 'qty' => __('Qty'), 'discount' => __('Discount'), 'refunded' => __('Refunded')])
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid {{ $c['border'] }};margin-top:24px;">
    @foreach ($order->items as $item)
        <tr>
            <td width="64" style="padding:14px 0;border-bottom:1px solid {{ $c['border'] }};vertical-align:middle;">
                @if ($item->image)
                    <img src="{{ $item->image }}" alt="" width="56" height="56" style="width:56px;height:56px;object-fit:cover;border-radius:8px;border:0;display:block;">
                @endif
            </td>
            <td style="padding:14px 12px;border-bottom:1px solid {{ $c['border'] }};vertical-align:middle;font-size:14px;">
                {{ $item->title }}
                @if ($item->variant_title)<br><span style="color:{{ $c['muted'] }};font-size:13px;">{{ $item->variant_title }}</span>@endif
                <br><span style="color:{{ $c['muted'] }};font-size:13px;">{{ $l['qty'] }}: {{ $item->quantity }}</span>
            </td>
            <td align="right" style="padding:14px 0;border-bottom:1px solid {{ $c['border'] }};vertical-align:middle;font-size:14px;white-space:nowrap;">{{ $money($item->total) }}</td>
        </tr>
    @endforeach
</table>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:12px;font-size:14px;">
    <tr><td style="padding:4px 0;color:{{ $c['muted'] }};">{{ $l['subtotal'] }}</td><td align="right">{{ $money($order->subtotal) }}</td></tr>
    @if ($order->discount_lines)
        @foreach ($order->discount_lines as $d)
            <tr><td style="padding:4px 0;color:{{ $c['muted'] }};">{{ $d['type'] === 'code' ? ($l['discount'] ?? 'Discount') : ($l['promotion'] ?? __('Promotion')) }} ({{ $d['title'] }})</td>
                <td align="right" style="color:{{ $c['primary'] }};">−{{ $money((int) $d['amount']) }}</td></tr>
        @endforeach
    @elseif ($order->discount_total > 0)
        <tr><td style="padding:4px 0;color:{{ $c['muted'] }};">{{ $l['discount'] ?? 'Discount' }}@if ($order->discount_code) ({{ $order->discount_code }})@endif</td>
            <td align="right" style="color:{{ $c['primary'] }};">−{{ $money($order->discount_total) }}</td></tr>
    @endif
    <tr><td style="padding:4px 0;color:{{ $c['muted'] }};">{{ $l['shipping'] }}@if (!empty($order->shipping_method['name'])) · {{ $order->shipping_method['name'] }}@endif</td>
        <td align="right">{{ $order->shipping_total === 0 ? $l['free'] : $money($order->shipping_total) }}</td></tr>
    @if ($order->tax_total > 0 && ! $order->taxes_included)
        <tr><td style="padding:4px 0;color:{{ $c['muted'] }};">{{ ($order->tax_lines[0]['name'] ?? __('Tax')).' '.($order->tax_lines[0]['rate'] ?? '').'%' }}</td>
            <td align="right">{{ $money($order->tax_total) }}</td></tr>
    @endif
    @if ($order->payment_fee > 0)
        <tr><td style="padding:4px 0;color:{{ $c['muted'] }};">{{ __('Cash on delivery fee') }}</td>
            <td align="right">{{ $money($order->payment_fee) }}</td></tr>
    @endif
    @if ($order->duty_total > 0)
        <tr><td style="padding:4px 0;color:{{ $c['muted'] }};">{{ __('Import duties') }}</td>
            <td align="right">{{ $money($order->duty_total) }}</td></tr>
    @endif
    @if ($order->gift_card_total > 0)
        <tr><td style="padding:4px 0;color:{{ $c['muted'] }};">{{ __('Gift card') }}</td>
            <td align="right" style="color:{{ $c['primary'] }};">−{{ $money($order->gift_card_total) }}</td></tr>
    @endif
    <tr><td style="padding:12px 0 0;font-size:16px;font-weight:700;border-top:1px solid {{ $c['border'] }};">{{ $l['total'] }}</td>
        <td align="right" style="padding:12px 0 0;font-size:16px;font-weight:700;border-top:1px solid {{ $c['border'] }};">{{ $money($order->total) }} <span style="font-size:12px;font-weight:400;color:{{ $c['muted'] }};">{{ $order->currency }}</span></td></tr>
    @if ($order->tax_total > 0 && $order->taxes_included)
        <tr><td style="padding:6px 0 0;color:{{ $c['muted'] }};font-size:13px;">{{ __('Including :tax', ['tax' => ($order->tax_lines[0]['name'] ?? __('Tax')).' '.($order->tax_lines[0]['rate'] ?? '').'%']) }}</td>
            <td align="right" style="padding:6px 0 0;color:{{ $c['muted'] }};font-size:13px;">{{ $money($order->tax_total) }}</td></tr>
    @endif
    @if ($order->refunded_total > 0)
        <tr><td style="padding:8px 0 0;color:{{ $c['muted'] }};">{{ $l['refunded'] ?? 'Refunded' }}</td>
            <td align="right" style="padding:8px 0 0;">−{{ $money($order->refunded_total) }}</td></tr>
    @endif
</table>
