<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $store->name }} · Unsubscribe</title>
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #F7F9F8; color: #1B2420; }
        .box { max-width: 420px; margin: 12vh auto 0; padding: 32px 24px; background: #fff; border: 1px solid #E3E8E5; border-radius: 12px; text-align: center; }
        h1 { font-size: 20px; margin: 0 0 12px; }
        p { color: #5B6660; line-height: 1.6; margin: 0 0 20px; }
        button { font: inherit; background: #0E8A5F; color: #fff; border: 0; border-radius: 8px; padding: 10px 20px; cursor: pointer; }
        button:focus-visible { outline: 2px solid #14A06E; outline-offset: 2px; }
    </style>
</head>
<body>
    <main class="box">
        <h1>{{ $store->name }}</h1>
        @if ($done)
            <p>You're unsubscribed. We won't email you again.<br>Ya no recibirás más correos. · Você não receberá mais e-mails.</p>
        @else
            <p>Stop receiving emails from {{ $store->name }}?</p>
            <form method="post" action="{{ url()->current() }}">
                @csrf
                <button type="submit">Unsubscribe</button>
            </form>
        @endif
    </main>
</body>
</html>
