{{-- Shown by the storefront service worker when there's no connection --}}
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('You’re offline') }} – {{ $store->name }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #fff; color: #1f2937; text-align: center; padding: 24px; }
        h1 { font-size: 22px; margin: 16px 0 8px; }
        p { color: #6b7280; margin: 0 0 24px; line-height: 1.6; }
        button { padding: 12px 28px; border: 0; border-radius: 999px; background: {{ $color }}; color: #fff; font: inherit; cursor: pointer; }
    </style>
</head>
<body>
    <main>
        <strong>{{ $store->name }}</strong>
        <h1>{{ __('You’re offline') }}</h1>
        <p>{{ __('Check your connection — the shop will be right here when you’re back.') }}</p>
        <button type="button" onclick="location.reload()">{{ __('Try again') }}</button>
    </main>
</body>
</html>
