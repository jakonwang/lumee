@extends($theme->view('checkout-layout'))

@section('title', __('Order :number', ['number' => $order->name()]))

@if ($order->created_at && $order->created_at->gt(now()->subDay()) && $order->financial_status !== 'voided')
    @push('tracking')
        @php
            $trackEvent = ['transaction_id' => $order->name(), 'currency' => $order->currency, 'value' => round($order->total / 100, 2), 'items' => $order->items->map(fn ($i) => ['id' => $i->variant_id ?? $i->product_id, 'name' => $i->title, 'price' => round($i->price / 100, 2), 'quantity' => $i->quantity])->values()];
        @endphp
        lumeeTrack('purchase', @json($trackEvent));
    @endpush
@endif

@section('content')
    @php($m = fn ($v) => \App\Support\Money::display($v, $order->currency))
    @php($a = $order->shipping_address)
    @php($date = fn ($d) => $d?->locale(app()->getLocale())->isoFormat('LL'))
    <div class="co">
        <div class="co-main">
            <div>
                @include($theme->view('partials.logo'))

                @if (session('error'))
                    <div class="errors">{{ session('error') }}</div>
                @endif
                @if (session('status'))
                    <div class="notice" role="status" style="margin:12px 0;padding:12px 14px;border-radius:8px;background:var(--rose-gold-50);border:1px solid var(--border);font-size:14px;">{{ session('status') }}</div>
                @endif

                <div style="display:flex; gap:16px; align-items:center; margin-top:8px;">
                    <div style="width:52px;height:52px;border-radius:50%;background:{{ $order->isCancelled() ? 'var(--ink-muted)' : 'var(--gradient-gem)' }};display:flex;align-items:center;justify-content:center;color:#fff;font-size:24px;flex:none;">{{ $order->isCancelled() ? '×' : '✓' }}</div>
                    <div>
                        <div style="font-size:13px;color:var(--ink-muted);">{{ __('Order :number', ['number' => $order->name()]) }}</div>
                        <div style="font-family:var(--font-display);font-size:30px;font-weight:600;">{{ __('Thank you, :name!', ['name' => $a['first_name']]) }}</div>
                    </div>
                </div>

                <div class="options" style="margin-top:28px; padding:20px;">
                    @if ($order->isCancelled())
                        <strong>{{ __('This order has been cancelled') }}</strong>
                        <p style="color:var(--ink-muted);margin:6px 0 0;font-size:14px;">
                            {{ __('Cancelled :date.', ['date' => $date($order->cancelled_at)]) }}
                            @if ($order->refunded_total > 0)
                                {!! __('A refund of :amount has been issued.', ['amount' => '<strong class="money">'.e($m($order->refunded_total)).'</strong>']) !!}
                            @elseif (! $order->isPaid())
                                {{ __('You have not been charged.') }}
                            @endif
                        </p>
                    @elseif ($order->financial_status === 'refunded')
                        <strong>{{ __('Your order has been refunded') }}</strong>
                        <p style="color:var(--ink-muted);margin:6px 0 0;font-size:14px;">{!! __('A refund of :amount has been issued to you.', ['amount' => '<strong class="money">'.e($m($order->refunded_total)).'</strong>']) !!}</p>
                    @elseif ($order->fulfillment_status === 'fulfilled')
                        <strong>{{ __('Your order is on its way') }}</strong>
                        <p style="color:var(--ink-muted);margin:6px 0 0;font-size:14px;">{{ __('Shipped :date. Bookmark this page to check your order status.', ['date' => $date($order->fulfilled_at)]) }}</p>
                    @elseif ($order->isPaid())
                        <strong>{{ __('Your order is confirmed') }}</strong>
                        <p style="color:var(--ink-muted);margin:6px 0 0;font-size:14px;">{{ __("We've received your payment and are preparing your order. Bookmark this page to check its status.") }}</p>
                    @elseif ($order->payment_gateway === 'stripe' && $order->events()->where('type', 'payment_pending')->exists())
                        <strong>{{ __('Waiting for your payment') }}</strong>
                        <p style="color:var(--ink-muted);margin:6px 0 0;font-size:14px;">{{ __('Pay the voucher or bank slip you received (OXXO, boleto…) before it expires — the payment instructions were also sent to your email. Your order will be confirmed automatically once the payment arrives.') }}</p>
                    @elseif ($order->payment_gateway === 'stripe')
                        <strong>{{ __('Awaiting payment confirmation') }}</strong>
                        <p style="color:var(--ink-muted);margin:6px 0 0;font-size:14px;">{{ __('If you completed payment, this page will update shortly. Refresh to check again.') }}</p>
                    @else
                        <strong>{{ __('Awaiting payment') }}</strong>
                        <p style="color:var(--ink-muted);margin:6px 0 0;font-size:14px;">{!! __('Please pay :amount using order number :number as the reference. Your order will ship once payment is received.', ['amount' => '<strong class="money">'.e($m($order->total)).'</strong>', 'number' => '<strong>'.e($order->name()).'</strong>']) !!}</p>
                        @if ($paymentInstructions ?? '')
                            <div style="margin-top:12px;padding:12px 14px;border:1px dashed var(--border, #ddd);border-radius:8px;font-size:14px;white-space:pre-line;">{{ $paymentInstructions }}</div>
                        @endif
                    @endif
                    @if ($order->financial_status === 'partially_refunded' && ! $order->isCancelled())
                        <p style="margin:12px 0 0;font-size:14px;">{!! __('A partial refund of :amount has been issued.', ['amount' => '<strong class="money">'.e($m($order->refunded_total)).'</strong>']) !!}</p>
                    @endif
                    @if ($order->tracking_number && ! $order->isCancelled())
                        @if ($order->tracking_status)
                            @include($theme->view('partials.tracking-timeline'))
                        @else
                            <p style="margin:12px 0 0;font-size:14px;">{{ __('Tracking:') }} {{ $order->tracking_company }} <strong>{{ $order->tracking_number }}</strong>
                                · <a href="https://t.17track.net/en#nums={{ rawurlencode($order->tracking_number) }}" target="_blank" rel="noopener">{{ __('Track your package') }}</a></p>
                        @endif
                    @endif
                </div>

                @php($downloads = $order->isPaid() && ! $order->isCancelled() ? \App\Catalog\DigitalDelivery::links($order, '') : [])
                @php($pickup = $order->shipping_method['pickup'] ?? null)
                @if ((int) $order->balance_due > 0 && ! $order->isCancelled())
                    <div class="options" style="margin-top:20px;padding:14px 16px;font-size:14px;">
                        <strong>{{ __('Balance due: :amount', ['amount' => $m((int) $order->balance_due)]) }}</strong>
                        <p style="color:var(--ink-muted);margin:6px 0 0;">{{ __('Your order was updated. The store will contact you about paying the difference.') }}</p>
                    </div>
                @endif
                @if ($downloads)
                    <div class="options" style="margin-top:20px;padding:14px 16px;font-size:14px;">
                        <h2 style="margin:0 0 8px;">{{ __('Your downloads') }}</h2>
                        @foreach ($downloads as $dl)
                            <div style="display:flex;justify-content:space-between;gap:12px;padding:6px 0;">
                                @if ($dl['usable'])<a href="{{ $dl['url'] }}">{{ $dl['filename'] }}</a>@else<span>{{ $dl['filename'] }}</span>@endif
                                <span style="color:var(--ink-muted);">{{ $dl['usable'] ? __(':count downloads left', ['count' => $dl['left']]) : __('Link expired') }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
                @if ($pickup)
                    <div class="options" style="margin-top:20px;padding:14px 16px;font-size:14px;line-height:1.7;">
                        <h2 style="margin:0 0 6px;">{{ __('Pickup') }}</h2>
                        <strong>{{ $pickup['name'] }}</strong><br>{{ $pickup['address'] ?? '' }}
                        @if (! empty($pickup['hours']))<br>{{ $pickup['hours'] }}@endif
                        @if (! empty($pickup['instructions']))<br><span style="color:var(--ink-muted);">{{ $pickup['instructions'] }}</span>@endif
                        <br><span style="color:var(--ink-muted);">{{ $order->fulfillment_status === 'fulfilled' ? __('Your order is ready for pickup.') : __('We’re preparing your order for pickup.') }}</span>
                    </div>
                @endif

                <div class="grid2" style="margin-top:28px; font-size:14px; line-height:1.7;">
                    <div>
                        <h2 style="margin-top:0">{{ __('Contact') }}</h2>
                        {{ $order->email }}
                        <h2>{{ __('Payment') }}</h2>
                        {{ ['stripe' => __('Credit / debit card'), 'paypal' => 'PayPal', 'airwallex' => __('Card & local payment methods'), 'razorpay' => __('UPI, cards & netbanking (Razorpay)'), 'cod' => __('Cash on delivery'), 'manual' => \App\Payments\PaymentSettings::for($store)->manualLabel(), 'test' => __('Test payment'), 'gift_card' => __('Gift card')][$order->payment_gateway] ?? $order->payment_gateway }}
                    </div>
                    <div>
                        <h2 style="margin-top:0">{{ __('Shipping address') }}</h2>
                        {{ $a['first_name'] }} {{ $a['last_name'] }}<br>
                        {{ $a['address1'] }}@if (!empty($a['address2'])), {{ $a['address2'] }}@endif<br>
                        {{ $a['city'] }}@if (!empty($a['province'])), {{ $a['province'] }}@endif {{ $a['zip'] }}<br>
                        {{ \App\Support\Countries::all()[$a['country']] ?? $a['country'] }}
                        <h2>{{ __('Shipping method') }}</h2>
                        {{ $order->shipping_method['name'] ?? '—' }}
                    </div>
                </div>

                @if (($subscriptions ?? collect())->isNotEmpty())
                    <div class="options" style="margin-top:20px;padding:14px 16px;font-size:14px;">
                        @foreach ($subscriptions as $s)
                            <div>↻ {{ \App\Subscriptions\Plans::label($s->interval) }} · <a href="/subscriptions/{{ $s->token }}">{{ __('Manage subscription') }}</a></div>
                        @endforeach
                    </div>
                @endif
                @php($ri = $returnInfo ?? null)
                @if ($ri && ($ri['requests']->isNotEmpty() || ! $ri['blocker']))
                    <section class="returns" aria-labelledby="returns-title" style="margin-top:28px;">
                        <h2 id="returns-title" style="margin-top:0">{{ __('Returns') }}</h2>
                        @foreach ($ri['requests'] as $rr)
                            <div class="options" style="padding:14px 16px;margin-bottom:12px;font-size:14px;">
                                <strong>{{ ['requested' => __('Return requested'), 'approved' => __('Return approved'), 'declined' => __('Return declined'), 'completed' => __('Return received')][$rr->status] }}</strong>
                                <span style="color:var(--ink-muted);"> · {{ $date($rr->created_at) }}</span>
                                @if ($rr->status === 'approved' && $rr->return_instructions)
                                    <div style="margin-top:8px;white-space:pre-line;">{{ $rr->return_instructions }}</div>
                                @elseif ($rr->status === 'declined' && $rr->merchant_note)
                                    <div style="margin-top:8px;white-space:pre-line;color:var(--ink-muted);">{{ $rr->merchant_note }}</div>
                                @elseif ($rr->status === 'requested')
                                    <div style="margin-top:6px;color:var(--ink-muted);">{{ __('We will review your request and email you.') }}</div>
                                @endif
                            </div>
                        @endforeach
                        @if (! $ri['blocker'] && $ri['returnable'])
                            <details class="return-form" @if ($errors->has('items') || $errors->has('reason')) open @endif>
                                <summary class="btn btn-ghost" style="display:inline-block;cursor:pointer;">{{ __('Request a return') }}</summary>
                                <form method="post" action="/orders/{{ $order->token }}/returns" style="margin-top:16px;display:grid;gap:12px;font-size:14px;">
                                    @csrf
                                    <p style="margin:0;color:var(--ink-muted);">{{ __('Returns are accepted within :days days of delivery. Choose what you would like to send back.', ['days' => $ri['window']]) }}</p>
                                    @foreach ($order->items as $item)
                                        @continue(! isset($ri['returnable'][$item->id]))
                                        <label style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
                                            <span>{{ $item->title }}@if ($item->variant_title)<span style="color:var(--ink-muted);"> · {{ $item->variant_title }}</span>@endif</span>
                                            <select name="items[{{ $item->id }}]" class="field" style="width:90px;" aria-label="{{ __('Quantity to return') }}">
                                                @for ($q = 0; $q <= $ri['returnable'][$item->id]; $q++)
                                                    <option value="{{ $q }}" @selected((int) old('items.'.$item->id) === $q)>{{ $q }}</option>
                                                @endfor
                                            </select>
                                        </label>
                                    @endforeach
                                    <label>{{ __('Reason') }}
                                        <select name="reason" class="field" required>
                                            @foreach ($ri['reasons'] as $key => $label)
                                                <option value="{{ $key }}" @selected(old('reason') === $key)>{{ __($label) }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label>{{ __('Anything else we should know? (optional)') }}
                                        <textarea name="note" class="field" rows="3" maxlength="1000">{{ old('note') }}</textarea>
                                    </label>
                                    @error('items')<div class="errors">{{ $message }}</div>@enderror
                                    <button type="submit" class="btn">{{ __('Submit return request') }}</button>
                                </form>
                            </details>
                        @endif
                    </section>
                @endif

                <a class="btn" href="/" style="text-align:center;text-decoration:none;">{{ __('Continue shopping') }}</a>
            </div>
        </div>

        <aside class="co-side">
            <div>
                @foreach ($order->items as $item)
                    <div class="item">
                        <div class="thumb">
                            @if ($item->image)<img src="{{ $item->image }}" alt="">@else<img class="ph" src="{{ $theme->placeholderUrl() }}" alt="">@endif
                            <span class="badge">{{ $item->quantity }}</span>
                        </div>
                        <div class="name">{{ $item->title }}@if ($item->variant_title)<small>{{ $item->variant_title }}</small>@endif
                            @if ($item->preorder)<small>{{ __('Pre-order') }}{{ ($ships = \App\Catalog\Preorders::config(\App\Models\Product::withoutGlobalScope('store')->find($item->product_id))['ships_at']) ? ' · '.__('ships around :date', ['date' => \Illuminate\Support\Carbon::parse($ships)->isoFormat('ll')]) : '' }}</small>@endif
                        </div>
                        <div class="money">{{ $m($item->total) }}</div>
                    </div>
                @endforeach
                <div class="row"><span>{{ __('Subtotal') }}</span><span class="money">{{ $m($order->subtotal) }}</span></div>
                @if ($order->discount_lines)
                    @foreach ($order->discount_lines as $d)
                        <div class="row discount"><span>{{ $d['type'] === 'code' ? __('Discount') : __('Promotion') }} <span class="code-tag">{{ $d['title'] }}</span></span><span class="money">−{{ $m((int) $d['amount']) }}</span></div>
                    @endforeach
                    @if ($order->discount_code && ! collect($order->discount_lines)->contains('type', 'code'))
                        <div class="row discount"><span>{{ __('Discount') }} <span class="code-tag">{{ $order->discount_code }}</span></span><span class="money">{{ __('Free shipping') }}</span></div>
                    @endif
                @elseif ($order->discount_total > 0 || $order->discount_code)
                    <div class="row discount"><span>{{ __('Discount') }} @if ($order->discount_code)<span class="code-tag">{{ $order->discount_code }}</span>@endif</span>
                        <span class="money">{{ $order->discount_total > 0 ? '−'.$m($order->discount_total) : __('Free shipping') }}</span></div>
                @endif
                <div class="row"><span>{{ __('Shipping') }}</span><span class="money">{{ $order->shipping_total === 0 ? __('Free') : $m($order->shipping_total) }}</span></div>
                @if ($order->tax_total > 0 && ! $order->taxes_included)
                    <div class="row"><span>{{ ($order->tax_lines[0]['name'] ?? __('Tax')).' '.($order->tax_lines[0]['rate'] ?? '').'%' }}</span><span class="money">{{ $m($order->tax_total) }}</span></div>
                @endif
                @if ($order->payment_fee > 0)
                    <div class="row"><span>{{ __('Cash on delivery fee') }}</span><span class="money">{{ $m($order->payment_fee) }}</span></div>
                @endif
                @if ($order->duty_total > 0)
                    <div class="row"><span>{{ __('Import duties') }}</span><span class="money">{{ $m($order->duty_total) }}</span></div>
                @endif
                @if ($order->gift_card_total > 0)
                    <div class="row discount"><span>{{ __('Gift card') }}</span><span class="money">−{{ $m($order->gift_card_total) }}</span></div>
                @endif
                <div class="row total"><span>{{ __('Total') }}</span><span><small>{{ $order->currency }}</small><span class="money">{{ $m($order->total) }}</span></span></div>
                @if ($order->tax_total > 0 && $order->taxes_included)
                    <div class="row" style="font-size:13px;color:var(--ink-muted)"><span>{{ __('Including :tax', ['tax' => ($order->tax_lines[0]['name'] ?? __('Tax')).' '.($order->tax_lines[0]['rate'] ?? '').'%']) }}</span><span class="money">{{ $m($order->tax_total) }}</span></div>
                @endif
                @if ($order->refunded_total > 0)
                    <div class="row"><span>{{ __('Refunded') }}</span><span class="money">−{{ $m($order->refunded_total) }}</span></div>
                @endif
            </div>
        </aside>
    </div>
@endsection
