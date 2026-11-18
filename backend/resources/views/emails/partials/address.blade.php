@php($a = $order->shipping_address)
{{ $a['first_name'] ?? '' }} {{ $a['last_name'] ?? '' }}<br>
{{ $a['address1'] ?? '' }}@if (!empty($a['address2'])), {{ $a['address2'] }}@endif<br>
{{ $a['city'] ?? '' }}@if (!empty($a['province'])), {{ $a['province'] }}@endif {{ $a['zip'] ?? '' }}<br>
{{ \App\Support\Countries::all()[$a['country'] ?? ''] ?? ($a['country'] ?? '') }}
