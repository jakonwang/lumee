@extends($theme->view('layout'))

@section('title', __('My account').' – '.$store->name)

@include($theme->view('account.partials.styles'))

@section('content')
    @php
        $a = $customer->default_address ?? [];
        $val = fn ($k) => old($k, $a[$k] ?? ($k === 'first_name' ? $customer->first_name : ($k === 'last_name' ? $customer->last_name : '')));
    @endphp
    <div class="wrap acct">
        <div class="acct-head">
            <div>
                <div class="eyebrow">{{ __('My account') }}</div>
                <h1 class="display">{{ $customer->first_name ? __('Hello, :name', ['name' => $customer->first_name]) : __('Hello') }}</h1>
            </div>
            <form method="post" action="/account/logout">
                @csrf
                <button class="link-btn" type="submit">{{ __('Log out') }}</button>
            </form>
        </div>

        @include($theme->view('account.partials.flash'))

        <div class="acct-grid">
            <div>
                @if (($loyalty ?? null) && $loyalty['enabled'])
                    @php
                        $m = fn ($v) => \App\Support\Money::display($v, $store->currency);
                    @endphp
                    <section class="panel" id="points">
                        <h2>{{ __('Rewards') }}</h2>
                        <p style="margin:0 0 6px;font-size:28px;font-weight:600;">{{ trans_choice(':count point|:count points', $customer->points, ['count' => number_format($customer->points)]) }}</p>
                        <p style="margin:0 0 14px;color:var(--ink-muted);font-size:14px;">
                            {{ __('Earn :rate points for every :unit you spend. :points points = :value off.', ['rate' => rtrim(rtrim(number_format($loyalty['earn_rate'], 2), '0'), '.'), 'unit' => $m(100), 'points' => $loyalty['redeem_points'], 'value' => $m(100)]) }}
                        </p>
                        @if ($customer->points >= $loyalty['min_redeem'])
                            <form method="post" action="/account/points/redeem" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                                @csrf
                                <input type="hidden" name="points" value="{{ $customer->points }}">
                                <button type="submit" class="btn">{{ __('Redeem for :value credit', ['value' => $m($pointsValue)]) }}</button>
                            </form>
                        @else
                            <p style="margin:0;font-size:14px;">{{ __('You can redeem from :count points.', ['count' => $loyalty['min_redeem']]) }}</p>
                        @endif
                        @error('points')<div class="errors" style="margin-top:10px;">{{ $message }}</div>@enderror
                        @if ($credits->isNotEmpty())
                            <h3 style="font-size:15px;margin:18px 0 8px;">{{ __('Your store credit') }}</h3>
                            @foreach ($credits as $credit)
                                <form method="post" action="/account/credit/{{ $credit->id }}/apply" style="display:flex;justify-content:space-between;align-items:center;gap:12px;padding:8px 0;border-top:1px solid var(--border);font-size:14px;">
                                    @csrf
                                    <span><strong>{{ $m($credit->balance) }}</strong> · •••• {{ $credit->last4 }}</span>
                                    <button type="submit" class="link-btn">{{ __('Use in my bag') }}</button>
                                </form>
                            @endforeach
                        @endif
                    </section>
                @endif
                @if ($company ?? null)
                    <section class="panel">
                        <h2>{{ __('Business account') }}</h2>
                        <p style="margin:0 0 6px;font-weight:600;">{{ $company->name }}</p>
                        <p style="margin:0;color:var(--ink-muted);font-size:14px;">
                            {{ $company->payment_terms ? __('Pay on account: Net :days', ['days' => $company->payment_terms]) : __('Pay at checkout') }}
                            @if ($company->tax_exempt) · {{ __('Tax exempt') }}@endif
                        </p>
                    </section>
                @endif
                @if (($quotes ?? collect())->isNotEmpty())
                    <section class="panel">
                        <h2>{{ __('Quotes') }}</h2>
                        @foreach ($quotes as $q)
                            <div style="display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-top:1px solid var(--border);font-size:14px;">
                                <a href="/quotes/{{ $q->token }}">{{ $q->name() }}</a>
                                <span style="color:var(--ink-muted);">{{ ['requested' => __('Waiting for prices'), 'sent' => __('Ready to accept'), 'accepted' => __('Accepted'), 'declined' => __('Declined')][$q->status] ?? $q->status }}</span>
                            </div>
                        @endforeach
                    </section>
                @endif
                @if (($subscriptions ?? collect())->isNotEmpty())
                    <section class="panel">
                        <h2>{{ __('Subscriptions') }}</h2>
                        @foreach ($subscriptions as $s)
                            <div style="display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-top:1px solid var(--border);font-size:14px;">
                                <span>{{ collect($s->items)->pluck('title')->implode(', ') }}<br><span style="color:var(--ink-muted);">{{ \App\Subscriptions\Plans::label($s->interval) }}</span></span>
                                <a href="/subscriptions/{{ $s->token }}">{{ __('Manage') }}</a>
                            </div>
                        @endforeach
                    </section>
                @endif
                <section class="panel">
                    <h2>{{ __('Orders') }}</h2>
                    @if (! $customer->isVerified())
                        <div class="notice">
                            {!! __('Please confirm your email address to see your orders. We sent a link to :email.', ['email' => '<strong>'.e($customer->email).'</strong>']) !!}
                            <form method="post" action="/account/verify/resend" style="display:inline;">
                                @csrf
                                <button class="link-btn" type="submit">{{ __('Send the link again') }}</button>
                            </form>
                        </div>
                    @elseif ($orders->isEmpty())
                        <p class="muted" style="margin:0;">{{ __("You haven't placed any orders yet.") }} <a href="/" style="color:var(--rose-gold-700);text-decoration:underline;">{{ __('Start shopping') }}</a></p>
                    @else
                        <table class="orders">
                            <thead><tr><th>{{ __('Order') }}</th><th class="hide-sm">{{ __('Date') }}</th><th>{{ __('Status') }}</th><th style="text-align:right">{{ __('Total') }}</th></tr></thead>
                            <tbody>
                                @foreach ($orders as $order)
                                    @php
                                        $status = match (true) {
                                            $order->isCancelled() => [__('Cancelled'), true],
                                            $order->financial_status === 'refunded' => [__('Refunded'), true],
                                            $order->financial_status === 'pending' => [__('Awaiting payment'), false],
                                            $order->fulfillment_status === 'fulfilled' => [__('Shipped'), false],
                                            default => [__('Processing'), false],
                                        };
                                    @endphp
                                    <tr>
                                        <td><a href="/orders/{{ $order->token }}">{{ $order->name() }}</a><div class="muted" style="font-size:12px;">{{ trans_choice(':count item|:count items', $order->items->sum('quantity')) }}</div></td>
                                        <td class="hide-sm">{{ $order->created_at->locale(app()->getLocale())->isoFormat('LL') }}</td>
                                        <td><span class="pill {{ $status[1] ? 'muted' : '' }}">{{ $status[0] }}</span></td>
                                        <td style="text-align:right" class="money">{{ \App\Support\Money::display($order->total - $order->refunded_total, $order->currency) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        @if ($orders->hasPages())
                            <div class="pager">
                                <span>@if ($orders->previousPageUrl())<a href="{{ $orders->previousPageUrl() }}">{{ __('‹ Newer') }}</a>@endif</span>
                                <span>@if ($orders->nextPageUrl())<a href="{{ $orders->nextPageUrl() }}">{{ __('Older ›') }}</a>@endif</span>
                            </div>
                        @endif
                    @endif
                </section>

                <section class="panel" id="address">
                    <h2>{{ __('Default address') }}</h2>
                    <p class="muted" style="margin:-8px 0 16px;">{{ __('Used to fill in checkout for you.') }}</p>
                    <form method="post" action="/account/address" class="form-stack">
                        @csrf
                        <select class="field" name="country" aria-label="{{ __('Country / Region') }}" autocomplete="country">
                            @foreach ($countries as $code => $name)
                                <option value="{{ $code }}" @selected($val('country') === $code || (! $val('country') && $code === 'US'))>{{ $name }}</option>
                            @endforeach
                        </select>
                        <div class="grid2">
                            <input class="field {{ $errors->has('first_name') ? 'invalid' : '' }}" name="first_name" value="{{ $val('first_name') }}" placeholder="{{ __('First name') }}" aria-label="{{ __('First name') }}" autocomplete="given-name" required>
                            <input class="field {{ $errors->has('last_name') ? 'invalid' : '' }}" name="last_name" value="{{ $val('last_name') }}" placeholder="{{ __('Last name') }}" aria-label="{{ __('Last name') }}" autocomplete="family-name" required>
                        </div>
                        <input class="field {{ $errors->has('address1') ? 'invalid' : '' }}" name="address1" value="{{ $val('address1') }}" placeholder="{{ __('Address') }}" aria-label="{{ __('Address') }}" autocomplete="address-line1" required>
                        <input class="field" name="address2" value="{{ $val('address2') }}" placeholder="{{ __('Apartment, suite, etc. (optional)') }}" aria-label="{{ __('Apartment') }}" autocomplete="address-line2">
                        <div class="grid3">
                            <input class="field {{ $errors->has('city') ? 'invalid' : '' }}" name="city" value="{{ $val('city') }}" placeholder="{{ __('City') }}" aria-label="{{ __('City') }}" autocomplete="address-level2" required>
                            <input class="field" name="province" value="{{ $val('province') }}" placeholder="{{ __('State / Province') }}" aria-label="{{ __('State or province') }}" autocomplete="address-level1">
                            <input class="field {{ $errors->has('zip') ? 'invalid' : '' }}" name="zip" value="{{ $val('zip') }}" placeholder="{{ __('ZIP / Postal code') }}" aria-label="{{ __('Postal code') }}" autocomplete="postal-code" required>
                        </div>
                        <input class="field" name="phone" value="{{ $val('phone') }}" placeholder="{{ __('Phone (optional)') }}" aria-label="{{ __('Phone') }}" autocomplete="tel">
                        <div><button class="btn" type="submit">{{ __('Save address') }}</button></div>
                    </form>
                </section>
            </div>

            <aside>
                <section class="panel">
                    <h2>{{ __('Details') }}</h2>
                    <form method="post" action="/account/profile" class="form-stack">
                        @csrf
                        <div class="muted">{{ $customer->email }}
                            @if ($customer->isVerified())<span class="pill" style="margin-left:6px;">{{ __('Confirmed') }}</span>@endif
                        </div>
                        <input class="field" name="first_name" value="{{ $customer->first_name }}" placeholder="{{ __('First name') }}" aria-label="{{ __('First name') }}" required>
                        <input class="field" name="last_name" value="{{ $customer->last_name }}" placeholder="{{ __('Last name') }}" aria-label="{{ __('Last name') }}" required>
                        <input class="field" name="phone" value="{{ $customer->phone }}" placeholder="{{ __('Phone (optional)') }}" aria-label="{{ __('Phone') }}">
                        <label style="display:grid;gap:4px;font-size:13px;color:var(--ink-muted);">{{ __('Birthday (optional) — we’ll send you a treat') }}
                            <input class="field" type="date" name="birthday" value="{{ old('birthday', $customer->birthday?->toDateString()) }}" max="{{ now()->subDay()->toDateString() }}">
                        </label>
                        <label class="check"><input type="checkbox" name="accepts_marketing" value="1" @checked($customer->accepts_marketing)> {{ __('Email me about new arrivals and offers') }}</label>
                        <button class="btn btn-ghost btn-block" type="submit">{{ __('Save details') }}</button>
                    </form>
                </section>

                <details class="panel" @if ($errors->has('current_password') || $errors->has('password')) open @endif>
                    <summary>{{ __('Change password') }}</summary>
                    <form method="post" action="/account/password" class="form-stack">
                        @csrf
                        <input class="field {{ $errors->has('current_password') ? 'invalid' : '' }}" type="password" name="current_password" placeholder="{{ __('Current password') }}" aria-label="{{ __('Current password') }}" autocomplete="current-password" required>
                        @error('current_password')<p class="err">{{ $message }}</p>@enderror
                        <input class="field {{ $errors->has('password') ? 'invalid' : '' }}" type="password" name="password" placeholder="{{ __('New password') }}" aria-label="{{ __('New password') }}" autocomplete="new-password" minlength="8" required>
                        <input class="field" type="password" name="password_confirmation" placeholder="{{ __('Confirm new password') }}" aria-label="{{ __('Confirm new password') }}" autocomplete="new-password" required>
                        @error('password')<p class="err">{{ $message }}</p>@enderror
                        <button class="btn btn-ghost btn-block" type="submit">{{ __('Change password') }}</button>
                    </form>
                </details>

                <details class="panel" @error('delete_password') open @enderror>
                    <summary>{{ __('Your data & privacy') }}</summary>
                    <p class="muted" style="font-size:13px;line-height:1.6;">{{ __('Download a copy of the personal data this store holds about you, or ask the store to delete your account.') }}</p>
                    <a class="btn btn-ghost btn-block" href="/account/data">{{ __('Download my data') }}</a>
                    <form method="post" action="/account/delete" class="form-stack" style="margin-top:12px;" onsubmit="return confirm(@js(__('Delete your account? Your order history will be anonymized and you won’t be able to sign in again.')))">
                        @csrf
                        <input class="field {{ $errors->has('delete_password') ? 'invalid' : '' }}" type="password" name="current_password" placeholder="{{ __('Current password') }}" aria-label="{{ __('Current password') }}" autocomplete="current-password" required>
                        @error('delete_password')<p class="err">{{ $message }}</p>@enderror
                        <button class="btn btn-ghost btn-block" type="submit">{{ __('Delete my account') }}</button>
                    </form>
                </details>
            </aside>
        </div>
    </div>
@endsection
