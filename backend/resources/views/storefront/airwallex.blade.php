<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Redirecting to payment…') }} – {{ $store->name }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: -apple-system, 'Segoe UI', Helvetica, Arial, sans-serif; color: #1C2621; background: #F7F9F8; padding: 16px; }
        .box { text-align: center; max-width: 380px; }
        .spin { width: 28px; height: 28px; border: 3px solid #DDE5E0; border-top-color: #0E8A5F; border-radius: 50%; margin: 0 auto 16px; animation: s 0.8s linear infinite; }
        @keyframes s { to { transform: rotate(360deg); } }
        p { color: #5C6B63; line-height: 1.6; }
        a { color: inherit; }
    </style>
</head>
<body>
    <main class="box">
        <div class="spin" aria-hidden="true"></div>
        <p>{{ __('Taking you to our secure payment page…') }}</p>
        <p id="fallback" hidden><a href="/orders/{{ $order->token }}">{{ __('Back to your order') }}</a></p>
    </main>
    {{-- Airwallex's hosted payment page (card data never touches this store) --}}
    <script src="https://checkout.airwallex.com/assets/elements.bundle.min.js"></script>
    <script>
        (function () {
            const o = @json($handoff);
            try {
                Airwallex.init({ env: o.env, origin: window.location.origin });
                Airwallex.redirectToCheckout({
                    env: o.env, mode: 'payment', intent_id: o.intent_id, client_secret: o.client_secret,
                    currency: o.currency, successUrl: o.successUrl, failUrl: o.failUrl,
                });
            } catch (e) {
                document.getElementById('fallback').hidden = false;
            }
            setTimeout(() => { document.getElementById('fallback').hidden = false; }, 8000);
        })();
    </script>
</body>
</html>
