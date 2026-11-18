@extends($theme->view('layout'))

@section('title', __('Your subscription').' · '.$store->name)
@section('noindex', '1')

@push('head')
<style>
    .sub-page { max-width: 760px; padding-block: 48px 80px; }
    .sub-page h1 { font-size: clamp(28px, 4vw, 38px); margin: 0 0 6px; }
    .sub-status { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 13px; background: var(--rose-gold-50); border: 1px solid var(--border); }
    .sub-card { border: 1px solid var(--border); border-radius: var(--radius-card, 12px); padding: 18px 20px; margin-top: 20px; }
    .sub-row { display: flex; justify-content: space-between; gap: 12px; padding: 8px 0; font-size: 15px; }
    .sub-row + .sub-row { border-top: 1px solid var(--border); }
    .sub-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 20px; }
    .sub-actions form { margin: 0; }
    .sub-muted { color: var(--ink-muted); font-size: 14px; }
    .sub-notice { margin: 16px 0 0; padding: 12px 14px; border-radius: 8px; background: var(--rose-gold-50); border: 1px solid var(--border); font-size: 14px; }
</style>
@endpush

@section('content')
    @php
        $date = fn ($d) => $d?->locale(app()->getLocale())->isoFormat('LL');
        $statusLabel = ['active' => __('Active'), 'paused' => __('Paused'), 'past_due' => __('Payment issue'), 'cancelled' => __('Cancelled')][$sub->status] ?? $sub->status;
    @endphp
    <section class="wrap sub-page">
        <div class="eyebrow">{{ __('Your subscription') }}</div>
        <h1 class="display">{{ \App\Subscriptions\Plans::label($sub->interval) }}</h1>
        <span class="sub-status">{{ $statusLabel }}</span>
        @if (session('status'))<div class="sub-notice" role="status">{{ session('status') }}</div>@endif

        <div class="sub-card">
            @foreach ($sub->items as $i)
                <div class="sub-row"><span>{{ $i['title'] }}@if (! empty($i['variant_title']))<span class="sub-muted"> · {{ $i['variant_title'] }}</span>@endif</span><span>× {{ $i['quantity'] }}</span></div>
            @endforeach
        </div>

        <div class="sub-card">
            @if ($sub->status === 'active')
                <div class="sub-row"><span>{{ __('Next delivery') }}</span><strong>{{ $date($sub->next_billing_at) }}</strong></div>
            @elseif ($sub->status === 'past_due')
                <div class="sub-row"><span>{{ __('We couldn’t take the last payment. Next try') }}</span><strong>{{ $date($sub->next_billing_at) }}</strong></div>
            @endif
            @if ($sub->discount > 0)
                <div class="sub-row"><span>{{ __('Subscriber discount') }}</span><span>{{ rtrim(rtrim(number_format($sub->discount, 2), '0'), '.') }}%</span></div>
            @endif
            <div class="sub-row"><span>{{ __('Ships to') }}</span><span class="sub-muted">{{ trim(($sub->shipping_address['first_name'] ?? '').' '.($sub->shipping_address['last_name'] ?? '')) }}, {{ $sub->shipping_address['city'] ?? '' }}</span></div>
        </div>

        @if ($sub->status !== 'cancelled')
            <div class="sub-actions">
                @if ($sub->status === 'active')
                    <form method="post" action="/subscriptions/{{ $sub->token }}">@csrf<input type="hidden" name="action" value="skip"><button class="btn btn-ghost" type="submit">{{ __('Skip next delivery') }}</button></form>
                    <form method="post" action="/subscriptions/{{ $sub->token }}">@csrf<input type="hidden" name="action" value="pause"><button class="btn btn-ghost" type="submit">{{ __('Pause') }}</button></form>
                @else
                    <form method="post" action="/subscriptions/{{ $sub->token }}">@csrf<input type="hidden" name="action" value="resume"><button class="btn" type="submit">{{ __('Resume') }}</button></form>
                @endif
                <form method="post" action="/subscriptions/{{ $sub->token }}" onsubmit="return confirm(@json(__('Cancel this subscription?')))">@csrf<input type="hidden" name="action" value="cancel"><button class="link-btn" type="submit">{{ __('Cancel subscription') }}</button></form>
            </div>
        @endif

        @if ($orders->isNotEmpty())
            <h2 style="font-size:20px;margin:36px 0 8px;">{{ __('Orders') }}</h2>
            @foreach ($orders->sortByDesc('id') as $o)
                <div class="sub-row"><a href="/orders/{{ $o->token }}">{{ $o->name() }}</a><span class="sub-muted">{{ $date($o->created_at) }} · {{ \App\Support\Money::display($o->total, $o->currency) }}</span></div>
            @endforeach
        @endif
    </section>
@endsection
