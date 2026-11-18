{{-- Package tracking on the order page: status steps + latest events. Expects $order. --}}
@php
    $status = $order->tracking_status;
    $labels = [
        'NotFound' => __('Waiting for the carrier'), 'InfoReceived' => __('Label created'), 'InTransit' => __('In transit'),
        'AvailableForPickup' => __('Ready for pickup'), 'OutForDelivery' => __('Out for delivery'), 'DeliveryFailure' => __('Delivery attempt failed'),
        'Delivered' => __('Delivered'), 'Exception' => __('Delivery issue'), 'Expired' => __('No recent updates'),
    ];
    // Progress bar: shipped → in transit → out for delivery → delivered
    $step = match ($status) {
        'Delivered' => 4, 'OutForDelivery', 'AvailableForPickup', 'DeliveryFailure' => 3, 'InTransit', 'Exception', 'Expired' => 2, default => 1,
    };
    $events = collect($order->tracking_events ?? [])->take(8);
    $trackUrl = 'https://t.17track.net/en#nums='.rawurlencode($order->tracking_number);
@endphp
<div class="trk">
    <div class="trk-head">
        <strong>{{ $labels[$status] ?? __('Shipped') }}</strong>
        <span class="trk-sub">{{ $order->tracking_carrier ?: $order->tracking_company }} · {{ $order->tracking_number }}</span>
    </div>
    <ol class="trk-steps" aria-label="{{ __('Delivery progress') }}">
        @foreach ([__('Shipped'), __('In transit'), __('Out for delivery'), __('Delivered')] as $i => $label)
            <li @class(['done' => $i < $step, 'warn' => $i === $step - 1 && in_array($status, ['Exception', 'DeliveryFailure'], true)])>{{ $label }}</li>
        @endforeach
    </ol>
    @if ($events->isNotEmpty())
        <ul class="trk-events">
            @foreach ($events as $e)
                <li>
                    <span class="trk-time">{{ $e['time'] ? \Illuminate\Support\Carbon::parse($e['time'])->format('M j, H:i') : '' }}</span>
                    <span>{{ $e['description'] }}@if ($e['location']) <span class="trk-sub">· {{ $e['location'] }}</span>@endif</span>
                </li>
            @endforeach
        </ul>
    @endif
    <a class="trk-link" href="{{ $trackUrl }}" target="_blank" rel="noopener">{{ __('Full tracking details') }} →</a>
</div>
<style>
    .trk { margin-top: 16px; padding: 16px 18px; border: 1px solid var(--border, #e5e5e5); border-radius: 12px; font-size: 14px; }
    .trk-head { display: grid; gap: 2px; }
    .trk-sub { color: var(--ink-muted); font-size: 13px; }
    .trk-steps { list-style: none; display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; padding: 0; margin: 14px 0 6px; }
    .trk-steps li { position: relative; padding-top: 12px; font-size: 12px; color: var(--ink-muted); }
    .trk-steps li::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; border-radius: 2px; background: var(--border, #e5e5e5); }
    .trk-steps li.done { color: var(--ink); }
    .trk-steps li.done::before { background: var(--rose-gold-500, #0E8A5F); }
    .trk-steps li.warn::before { background: #D97706; }
    .trk-events { list-style: none; padding: 0; margin: 12px 0 0; display: grid; gap: 10px; border-top: 1px solid var(--border, #e5e5e5); padding-top: 12px; }
    .trk-events li { display: grid; grid-template-columns: 96px 1fr; gap: 10px; line-height: 1.5; }
    .trk-time { color: var(--ink-muted); font-size: 12px; font-variant-numeric: tabular-nums; }
    .trk-link { display: inline-block; margin-top: 12px; font-size: 13px; color: var(--rose-gold-700, #0A7350); }
    @media (max-width: 520px) { .trk-events li { grid-template-columns: 1fr; gap: 0; } }
</style>
