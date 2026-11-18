@extends('emails.layout')

@php
    // One row per product; links open the product's review form with the buyer's name and email filled in
    $prefix = \App\Notifications\OrderMail::localePrefix($order->locale, $store);
    $handles = \App\Models\Product::withoutGlobalScope('store')->where('store_id', $store->id)->where('status', 'active')
        ->whereIn('id', $order->items->pluck('product_id')->filter())->pluck('handle', 'id');
    $products = $order->items->unique(fn ($i) => $i->product_id ?? $i->title)->values();
    $reviewUrl = fn ($item) => isset($handles[$item->product_id])
        ? $store->storefrontUrl().$prefix.'/products/'.$handles[$item->product_id].'/review?order='.$order->token
        : $store->storefrontUrl().$prefix.'/';
    $first = $order->shipping_address['first_name'] ?? null;
@endphp

@section('preheader'){{ __('Tell us what you think — it only takes a minute.') }}@endsection

@section('content')
    <h1 style="margin:0 0 16px;font-family:{{ $c['serif'] }};font-size:28px;font-weight:normal;">{{ $first ? __('How is everything, :name?', ['name' => $first]) : __('How is everything?') }}</h1>
    <p style="margin:0 0 20px;">{{ __('We hope you’re enjoying your order :number. Would you share a quick review? It helps other shoppers — and helps us get better.', ['number' => $order->name()]) }}</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid {{ $c['border'] }};">
        @foreach ($products as $item)
            <tr>
                <td width="64" style="padding:14px 0;border-bottom:1px solid {{ $c['border'] }};vertical-align:middle;">
                    @if ($item->image)
                        <img src="{{ \Illuminate\Support\Str::startsWith($item->image, ['http://', 'https://']) ? $item->image : $store->storefrontUrl().$item->image }}" alt="" width="56" height="56" style="width:56px;height:56px;object-fit:cover;border-radius:8px;border:0;display:block;">
                    @endif
                </td>
                <td style="padding:14px 12px;border-bottom:1px solid {{ $c['border'] }};vertical-align:middle;font-size:14px;">
                    {{ $item->title }}
                    <div style="margin-top:4px;color:{{ $c['primary'] }};font-size:18px;letter-spacing:3px;">☆☆☆☆☆</div>
                </td>
                <td align="right" style="padding:14px 0;border-bottom:1px solid {{ $c['border'] }};vertical-align:middle;white-space:nowrap;">
                    <a href="{{ $reviewUrl($item) }}" style="display:inline-block;padding:9px 16px;border-radius:999px;background:{{ $c['primary'] }};color:{{ $c['on_primary'] }};font-size:13px;text-decoration:none;">{{ __('Write a review') }}</a>
                </td>
            </tr>
        @endforeach
    </table>
@endsection

@section('footer')
    @include('emails.partials.customer-footer')
@endsection
