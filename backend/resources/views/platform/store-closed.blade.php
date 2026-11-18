<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $store->name }} – Temporarily closed</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #F7F9F8; color: #1C2621;
               font-family: -apple-system, 'Segoe UI', Helvetica, Arial, sans-serif; padding: 16px; box-sizing: border-box; }
        .card { max-width: 440px; width: 100%; background: #fff; border: 1px solid #E5EAE7; border-radius: 12px; padding: 40px 32px; text-align: center; }
        h1 { font-size: 24px; margin: 0 0 12px; }
        p { color: #6B7770; line-height: 1.7; margin: 0; font-size: 15px; }
        .dot { width: 10px; height: 10px; border-radius: 50%; background: #0E8A5F; display: inline-block; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="card">
        <span class="dot" aria-hidden="true"></span>
        <h1>{{ $store->name }}</h1>
        <p>This store is temporarily closed. Please check back soon.</p>
        <p style="margin-top:12px;font-size:13px;">Placed an order? The link in your confirmation email still shows its status.</p>
    </div>
</body>
</html>
