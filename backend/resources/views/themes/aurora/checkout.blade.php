@extends($theme->view('checkout-layout'))

@section('title', __('Checkout'))

@push('tracking')
    @php
        $trackEvent = ['currency' => $store->currency, 'value' => round($cart->subtotal() / 100, 2), 'items' => $cart->items->filter(fn ($i) => $i->variant?->product)->map(fn ($i) => ['id' => $i->variant_id, 'name' => $i->variant->product->title, 'price' => round($i->variant->price / 100, 2), 'quantity' => $i->quantity])->values()];
    @endphp
    lumeeTrack('begin_checkout', @json($trackEvent));
@endpush

@section('content')
    {{-- Signed-in customers get their email and saved address filled in --}}
    @php
        $m = fn ($v) => \App\Support\Money::display($v, $store->currency);
        $subtotal = $cart->subtotal();
        $selectedRate = (int) old('shipping_rate_id', $rates->first()?->id);
        $off = $discounts['amount'];   // automatic promotions + code
        $freeShip = $discounts['free_shipping'];
        $shipping = $freeShip ? 0 : ($rates->firstWhere('id', $selectedRate)?->price ?? 0);
        $invalid = fn ($f) => $errors->has($f) ? 'invalid' : '';
        $prefill = $customer
            ? array_merge(['first_name' => $customer->first_name, 'last_name' => $customer->last_name, 'phone' => $customer->phone], $customer->default_address ?? [], ['email' => $customer->email])
            : [];
        $pre = fn ($k) => old($k, $prefill[$k] ?? '');
        // Same math as TaxService (the order is recalculated on the server anyway)
        $taxOf = function (int $ship) use ($tax) {
            if (! $tax['rate']) return 0;
            $base = $tax['taxable_items'] + ($tax['shipping'] ? $ship : 0);
            return $tax['included'] ? (int) round($base - $base / (1 + $tax['rate'] / 100)) : (int) round($base * $tax['rate'] / 100);
        };
        $taxAmount = $taxOf($shipping);
        $taxLabel = $tax['rate'] ? trim(($tax['name'] ?: __('Tax')).' '.rtrim(rtrim(number_format($tax['rate'], 3, '.', ''), '0'), '.').'%') : '';
    @endphp

    <div class="co">
        <div class="co-main">
            <div>
                @include($theme->view('partials.logo'))

                @if ($errors->any())
                    <div class="errors"><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
                @endif

                <form method="post" action="/checkout" id="checkout">
                    @csrf
                    <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;">
                        <h2>{{ __('Contact') }}</h2>
                        @if ($customer)
                            <span style="font-size:13px;color:var(--ink-muted);">{{ __('Signed in as :email', ['email' => $customer->email]) }}</span>
                        @else
                            <a href="/account/login?return=/checkout" style="font-size:13px;color:var(--ink-muted);text-decoration:underline;">{{ __('Have an account? Log in') }}</a>
                        @endif
                    </div>
                    <label class="sr" for="email">{{ __('Email') }}</label>
                    <input class="field {{ $invalid('email') }}" id="email" type="email" name="email" value="{{ $pre('email') }}" placeholder="{{ __('Email') }}" autocomplete="email" required>
                    <label class="consent"><input type="checkbox" name="accepts_marketing" value="1" @checked(old('accepts_marketing', $customer?->accepts_marketing))> {{ __('Email me with news and offers') }}</label>

                    <h2>{{ __('Shipping address') }}</h2>
                    @if (($company ?? null) && $company->locations)
                        <div class="stack" style="margin-bottom:10px;">
                            <select class="field" id="company-location" aria-label="{{ __('Ship to a company location') }}">
                                <option value="">{{ __('Ship to a company location…') }}</option>
                                @foreach ($company->locations as $n => $loc)
                                    <option value="{{ $n }}">{{ $loc['name'] ?? ($loc['address1'] ?? '') }} — {{ $loc['city'] ?? '' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <script>
                            (function () {
                                const locations = @json($company->locations);
                                document.getElementById('company-location').addEventListener('change', function () {
                                    const loc = locations[this.value];
                                    if (!loc) return;
                                    ['first_name', 'last_name', 'address1', 'address2', 'city', 'province', 'zip', 'phone', 'country'].forEach(function (k) {
                                        const el = document.querySelector('#checkout [name="' + k + '"]');
                                        if (el && loc[k] != null) { el.value = loc[k]; el.dispatchEvent(new Event('change', { bubbles: true })); }
                                    });
                                });
                            })();
                        </script>
                    @endif
                    <div class="stack">
                        <select class="field {{ $invalid('country') }}" name="country" aria-label="{{ __('Country / Region') }}" autocomplete="country">
                            @foreach ($countries as $code => $name)
                                <option value="{{ $code }}" @selected($country === $code)>{{ $name }}</option>
                            @endforeach
                        </select>
                        <div class="grid2">
                            <input class="field {{ $invalid('first_name') }}" name="first_name" value="{{ $pre('first_name') }}" placeholder="{{ __('First name') }}" aria-label="{{ __('First name') }}" autocomplete="given-name" required>
                            <input class="field {{ $invalid('last_name') }}" name="last_name" value="{{ $pre('last_name') }}" placeholder="{{ __('Last name') }}" aria-label="{{ __('Last name') }}" autocomplete="family-name" required>
                        </div>
                        <input class="field {{ $invalid('address1') }}" name="address1" value="{{ $pre('address1') }}" placeholder="{{ __('Address') }}" aria-label="{{ __('Address') }}" autocomplete="address-line1" required>
                        <input class="field" name="address2" value="{{ $pre('address2') }}" placeholder="{{ __('Apartment, suite, etc. (optional)') }}" aria-label="{{ __('Apartment') }}" autocomplete="address-line2">
                        <div class="grid3">
                            <input class="field {{ $invalid('city') }}" name="city" value="{{ $pre('city') }}" placeholder="{{ __('City') }}" aria-label="{{ __('City') }}" autocomplete="address-level2" required>
                            <input class="field" name="province" value="{{ $pre('province') }}" placeholder="{{ __('State / Province') }}" aria-label="{{ __('State / Province') }}" autocomplete="address-level1">
                            <input class="field {{ $invalid('zip') }}" name="zip" value="{{ $pre('zip') }}" placeholder="{{ __('ZIP / Postal code') }}" aria-label="{{ __('ZIP / Postal code') }}" autocomplete="postal-code" required>
                        </div>
                        <input class="field" name="phone" value="{{ $pre('phone') }}" placeholder="{{ __('Phone (optional, for delivery updates)') }}" aria-label="{{ __('Phone') }}" autocomplete="tel">
                        {{-- Customs in some countries need the buyer's tax ID (Brazil: CPF / CNPJ) --}}
                        @php
                            $taxIdLabel = $taxIdLabels[old('country', $country)] ?? null;
                        @endphp
                        <div id="tax-id-wrap" @if (! $taxIdLabel) hidden @endif>
                            <input class="field {{ $invalid('tax_id') }}" name="tax_id" value="{{ $pre('tax_id') }}" placeholder="{{ $taxIdLabel }}" aria-label="{{ $taxIdLabel ?? __('Tax ID') }}" @if ($taxIdLabel) required @endif>
                            <div class="hint" style="font-size:12px;color:var(--ink-muted);margin-top:4px">{{ __('Required by customs in this country.') }}</div>
                            @error('tax_id')<div class="error">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <h2>{{ __('Shipping method') }}</h2>
                    <div class="options" id="shipping-options" aria-live="polite">
                        @forelse ($rates as $rate)
                            <label class="option">
                                <input type="radio" name="shipping_rate_id" value="{{ $rate->id }}" data-price="{{ $rate->price }}" @checked($selectedRate === $rate->id)>
                                <span class="grow">{{ $rate->name }}@if ($rate->description)<span class="sub">{{ $rate->description }}</span>@endif</span>
                                <span class="money">{{ $rate->price === 0 ? __('Free') : $m($rate->price) }}</span>
                            </label>
                        @empty
                            <div class="option"><span class="grow">{{ __("Sorry, we don't ship to this country yet.") }}</span></div>
                        @endforelse
                    </div>

                    <h2>{{ __('Payment') }}</h2>
                    <div class="options">
                        @foreach ($gateways as $key => $gateway)
                            <label class="option">
                                <input type="radio" name="payment_gateway" value="{{ $key }}" @checked(old('payment_gateway', $gateways->keys()->first()) === $key)>
                                <span class="grow">
                                    {{ $gateway->label() }}
                                    <span class="sub">
                                        @switch($key)
                                            @case('stripe') {{ __("You'll be redirected to a secure Stripe page to pay.") }} @break
                                            @case('manual')
                                                @if ($paymentInstructions)
                                                    <span style="white-space:pre-line;display:block;">{{ $paymentInstructions }}</span>
                                                @else
                                                    {{ __('Pay after ordering. Your order ships once payment is received.') }}
                                                @endif
                                                @break
                                            @case('test') {{ __('Marks the order as paid instantly. Development only.') }} @break
                                            @case('terms') {{ __('Your order is placed now and invoiced to :company. Pay within the agreed terms.', ['company' => $company?->name ?? '']) }} @break
                                            @case('cod')
                                                {{ __('Pay the courier in cash when your order arrives.') }}
                                                @if ($cod['fee'] > 0) {{ __('A :fee cash-on-delivery fee applies.', ['fee' => $m($cod['fee'])]) }} @endif
                                                @if ($cod['max'] > 0) {{ __('For orders up to :amount.', ['amount' => $m($cod['max'])]) }} @endif
                                                @break
                                        @endswitch
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('payment_gateway')<div class="error">{{ $message }}</div>@enderror

                    @if ($company ?? null)
                        <div class="stack" style="margin-top:16px;">
                            <input class="field" name="po_number" value="{{ old('po_number') }}" maxlength="60" placeholder="{{ __('PO number (optional)') }}" aria-label="{{ __('PO number (optional)') }}">
                        </div>
                    @endif
                    @if ($taxExempt ?? false)
                        <p style="font-size:13px;color:var(--ink-muted);margin:12px 0 0;">{{ __('Your account is tax exempt — no tax is charged on this order.') }}</p>
                    @endif
                    @if ($px->isConverted())
                        <p style="font-size:13px;color:var(--ink-muted);margin:16px 0 0;">
                            {{ __('Your order will be charged in :currency. Prices you saw in :shown were estimates; your bank sets the final exchange rate.', ['currency' => $store->currency, 'shown' => $px->currency()]) }}
                        </p>
                    @endif
                    <button class="btn" type="submit">{{ __('Complete order') }}</button>
                    <div class="secure">🔒 {{ __('Secure checkout · Card details are never stored by this store') }}</div>
                    <a class="back" href="/cart">‹ {{ __('Return to bag') }}</a>
                </form>
            </div>
        </div>

        <aside class="co-side">
            <div>
                @foreach ($cart->items as $item)
                    @php $p = $item->variant->product; @endphp
                    <div class="item">
                        <div class="thumb">
                            @if ($item->imageUrl())<img src="{{ $item->imageUrl() }}" alt="">@else<img class="ph" src="{{ $theme->placeholderUrl() }}" alt="">@endif
                            <span class="badge">{{ $item->quantity }}</span>
                        </div>
                        <div class="name">{{ $p->title }}@if ($item->variant->title !== 'Default')<small>{{ $item->variant->title }}</small>@endif @if ($item->plan)<small>↻ {{ $item->planLabel() }}</small>@endif</div>
                        <div class="money">{{ $m($item->lineTotal()) }}</div>
                    </div>
                @endforeach

                <div class="row"><span>{{ __('Subtotal') }}</span><span class="money">{{ $m($subtotal) }}</span></div>
                @foreach ($discounts['lines'] as $line)
                    <div class="row discount"><span>{{ $line['type'] === 'code' ? __('Discount') : __('Promotion') }} <span class="code-tag">{{ $line['title'] }}</span></span>
                        <span class="money">−{{ $m($line['amount']) }}</span></div>
                @endforeach
                @if ($discount && $freeShip)
                    <div class="row discount"><span>{{ __('Discount') }} <span class="code-tag">{{ $discount->code }}</span></span><span class="money">{{ $discount->label($store->currency) }}</span></div>
                @elseif ($discount && $discounts['code_blocked'])
                    <div class="row discount-note">{{ __('Code :code can’t be combined with the current promotion, which saves you more.', ['code' => $discount->code]) }}</div>
                @endif
                <div class="row"><span>{{ __('Shipping') }}</span><span class="money" id="shipping-amount">{{ $shipping === 0 ? __('Free') : $m($shipping) }}</span></div>
                <div class="row" id="tax-row" @if (! $taxAmount || $tax['included']) hidden @endif>
                    <span id="tax-label">{{ $taxLabel }}</span><span class="money" id="tax-amount">{{ $m($taxAmount) }}</span>
                </div>
                @if ($duties['amount'] > 0)
                    <div class="row"><span>{{ __('Import duties') }}</span><span class="money">{{ $m($duties['amount']) }}</span></div>
                @endif
                @php($codFee = old('payment_gateway', $gateways->keys()->first()) === 'cod' ? (int) ($cod['fee'] ?? 0) : 0)
                <div class="row" id="cod-fee-row" @if (! $codFee) hidden @endif><span>{{ __('Cash on delivery fee') }}</span><span class="money">{{ $m((int) ($cod['fee'] ?? 0)) }}</span></div>
                @php($due = $subtotal - $off + $shipping + ($tax['included'] ? 0 : $taxAmount) + $duties['amount'] + $codFee)
                @if ($giftCard ?? null)
                    <div class="row discount"><span>{{ __('Gift card') }} <span class="code-tag">•••• {{ $giftCard->last4 }}</span></span>
                        <span class="money" id="gift-card-amount">−{{ $m(min($giftCard->balance, $due)) }}</span></div>
                @endif
                <div class="row total"><span>{{ __('Total') }}</span><span><small>{{ $store->currency }}</small><span class="money" id="total-amount">{{ $m(max(0, $due - (($giftCard ?? null) ? min($giftCard->balance, $due) : 0))) }}</span></span></div>
                <div class="row" id="tax-included" style="font-size:13px;color:var(--ink-muted);margin-top:-4px;" @if (! $taxAmount || ! $tax['included']) hidden @endif>
                    <span>{{ __('Including :tax', ['tax' => '']) }}<span id="tax-included-label">{{ $taxLabel }}</span></span><span class="money" id="tax-included-amount">{{ $m($taxAmount) }}</span>
                </div>
                @if ($duties['mode'] === 'included')
                    <div class="row" style="font-size:13px;color:var(--ink-muted)"><span>{{ __('Import duties are included — nothing more to pay on delivery.') }}</span></div>
                @elseif ($duties['mode'] === 'on_delivery')
                    <div class="row" style="font-size:13px;color:var(--ink-muted)"><span>{{ __('Import duties and taxes may be collected by the carrier on delivery.') }}</span></div>
                @endif
            </div>
        </aside>
    </div>

    <script>
        // Live-update the summary when the shipping method changes (server recalculates on submit)
        (function () {
            const subtotal = {{ $subtotal - $off }};   // after discount
            const freeShip = {{ $freeShip ? 'true' : 'false' }};
            const giftBalance = {{ ($giftCard ?? null) ? (int) $giftCard->balance : 0 }};
            const duty = {{ (int) $duties['amount'] }};   // import duties on the goods (don't depend on shipping)
            const codFee = {{ (int) ($cod['fee'] ?? 0) }};   // added when paying cash on delivery
            const taxIdLabels = @json($taxIdLabels);
            const fmt = new Intl.NumberFormat(@json(\App\Support\Money::locale() ?? 'en-US'), { style: 'currency', currency: @json($store->currency) });   // same as Money::display
            const box = document.getElementById('shipping-options');
            let tax = @json($tax);
            const $id = (id) => document.getElementById(id);
            function taxOf(ship) {
                if (!tax || !tax.rate) return 0;
                const base = tax.taxable_items + (tax.shipping ? ship : 0);
                return tax.included ? Math.round(base - base / (1 + tax.rate / 100)) : Math.round(base * tax.rate / 100);
            }
            function updateTotals() {
                const r = box.querySelector('input[name="shipping_rate_id"]:checked');
                const price = !r || freeShip ? 0 : Number(r.dataset.price);
                const t = taxOf(price);
                const label = tax && tax.rate ? ((tax.name || @json(__('Tax'))) + ' ' + Number(tax.rate) + '%') : '';
                $id('shipping-amount').textContent = !r ? '—' : (price === 0 ? @json(__('Free')) : fmt.format(price / 100));
                $id('tax-row').hidden = !t || tax.included;
                $id('tax-included').hidden = !t || !tax.included;
                $id('tax-label').textContent = $id('tax-included-label').textContent = label;
                $id('tax-amount').textContent = $id('tax-included-amount').textContent = fmt.format(t / 100);
                const cod = (document.querySelector('input[name="payment_gateway"]:checked') || {}).value === 'cod';
                if ($id('cod-fee-row')) $id('cod-fee-row').hidden = !cod || !codFee;
                const due = subtotal + price + (tax && !tax.included ? t : 0) + duty + (cod ? codFee : 0);
                const gift = Math.min(giftBalance, due);
                if ($id('gift-card-amount')) $id('gift-card-amount').textContent = '−' + fmt.format(gift / 100);
                $id('total-amount').textContent = fmt.format((due - gift) / 100);
            }
            box.addEventListener('change', updateTotals);
            document.querySelectorAll('input[name="payment_gateway"]').forEach(function (el) { el.addEventListener('change', updateTotals); });

            // Each country can have its own shipping zone → reload the rates when it changes
            const esc = function (s) { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
            const countryEl = document.querySelector('select[name="country"]');
            const provinceEl = document.querySelector('input[name="province"]');
            const zipEl = document.querySelector('#checkout [name="zip"]');
            const cityEl = document.querySelector('#checkout [name="city"]');
            function refresh() {
                box.style.opacity = '.5';
                const checked = box.querySelector('input[name="shipping_rate_id"]:checked');
                fetch(@json(app(\App\I18n\Localization::class)->url('/checkout/shipping-rates')) + '?country=' + encodeURIComponent(countryEl.value) + '&province=' + encodeURIComponent(provinceEl ? provinceEl.value : '') + '&zip=' + encodeURIComponent(zipEl ? zipEl.value : '') + '&city=' + encodeURIComponent(cityEl ? cityEl.value : ''), { headers: { Accept: 'application/json' } })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        tax = data.tax;
                        const keep = checked && data.rates.some(function (r) { return String(r.id) === checked.value; }) ? checked.value : null;
                        box.innerHTML = data.rates.length ? data.rates.map(function (r, i) {
                            const on = keep ? String(r.id) === keep : i === 0;
                            return '<label class="option"><input type="radio" name="shipping_rate_id" value="' + r.id + '" data-price="' + r.price + '"' + (on ? ' checked' : '') + '>'
                                + '<span class="grow">' + esc(r.name) + (r.description ? '<span class="sub">' + esc(r.description) + '</span>' : '') + '</span>'
                                + '<span class="money">' + esc(r.display) + '</span></label>';
                        }).join('') : '<div class="option"><span class="grow">' + esc(@json(__("Sorry, we don't ship to this country yet."))) + '</span></div>';
                        updateTotals();
                    })
                    .finally(function () { box.style.opacity = ''; });
            }
            // Shipping zones and tax rates depend on the country (and state/province for tax)
            countryEl.addEventListener('change', refresh);
            // Tax ID only for the countries whose customs need it
            countryEl.addEventListener('change', function () {
                const wrap = $id('tax-id-wrap');
                const input = wrap.querySelector('input');
                const label = taxIdLabels[countryEl.value];
                wrap.hidden = !label;
                input.required = !!label;
                input.placeholder = label || '';
                input.setAttribute('aria-label', label || '');
            });
            if (provinceEl) provinceEl.addEventListener('change', refresh);
            if (zipEl) zipEl.addEventListener('change', refresh);   // address-level US sales tax
            document.getElementById('checkout').addEventListener('submit', function (e) {
                const btn = e.target.querySelector('button[type="submit"]');
                btn.disabled = true; btn.textContent = @json(__('Processing…'));
            });

            // Save the email as soon as it's entered (used for the "you left something behind" reminder)
            const emailEl = document.getElementById('email');
            const tokenEl = document.querySelector('#checkout input[name="_token"]');
            let savedEmail = '';
            if (emailEl && tokenEl) {
                emailEl.addEventListener('change', function () {
                    const v = emailEl.value.trim();
                    if (!v || v === savedEmail || !emailEl.checkValidity()) return;
                    savedEmail = v;
                    fetch(@json(app(\App\I18n\Localization::class)->url('/checkout/contact')), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': tokenEl.value },
                        body: JSON.stringify({ email: v }),
                    }).catch(function () {});
                });
            }
        })();
    </script>
@endsection
