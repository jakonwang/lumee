<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Unsubscribe') }} – {{ $store->name }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #F7F9F8; color: #1C2621;
            font-family: -apple-system, 'Segoe UI', Helvetica, Arial, sans-serif; padding: 16px; }
        .card { background: #fff; border: 1px solid #E5EAE7; border-radius: 14px; padding: 32px; max-width: 440px; width: 100%; text-align: center; }
        h1 { font-size: 22px; margin: 0 0 10px; }
        p { color: #5C6B63; line-height: 1.6; margin: 0 0 20px; }
        button { background: #1C2621; color: #fff; border: 0; border-radius: 999px; padding: 12px 28px; font-size: 15px; cursor: pointer; }
        a { color: inherit; }
    </style>
</head>
<body>
    <main class="card">
        <h1>{{ $store->name }}</h1>
        @if ($done)
            <p>{{ __(':email has been unsubscribed. You won’t receive marketing emails from us anymore.', ['email' => $email]) }}</p>
            <p><small>{{ __('Order updates (confirmations, shipping notices) will still be sent.') }}</small></p>
        @else
            <p>{{ __('Unsubscribe :email from marketing emails?', ['email' => $email]) }}</p>
            <form method="post" action="/email/unsubscribe/{{ $token }}">
                @csrf
                <button type="submit">{{ __('Unsubscribe') }}</button>
            </form>
        @endif
        <p style="margin: 20px 0 0"><a href="/">{{ __('Back to the store') }}</a></p>
    </main>
</body>
</html>
