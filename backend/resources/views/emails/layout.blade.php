{{-- Table layout + inline styles: email clients (Gmail, Outlook) ignore most CSS. $c = palette from OrderMail. --}}
<!doctype html>
<html lang="@yield('lang', 'en')">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $store->name }}</title>
</head>
<body style="margin:0;padding:0;background:{{ $c['bg'] }};font-family:{{ $c['sans'] }};color:{{ $c['ink'] }};">
    {{-- Inbox preview line --}}
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;">@yield('preheader')</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{{ $c['bg'] }};">
        <tr><td align="center" style="padding:32px 12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#FFFFFF;border:1px solid {{ $c['border'] }};border-radius:12px;">
                <tr><td align="center" style="padding:32px 32px 8px;">
                    <a href="{{ $store->storefrontUrl() }}" style="text-decoration:none;color:{{ $c['primary'] }};">
                        @if ($theme->logoUrl())
                            <img src="{{ $theme->logoUrl() }}" alt="{{ $store->name }}" height="44" style="height:44px;width:auto;border:0;display:block;">
                        @else
                            <span style="font-family:{{ $c['serif'] }};font-size:24px;letter-spacing:6px;text-transform:uppercase;color:{{ $c['primary'] }};">{{ $store->name }}</span>
                        @endif
                    </a>
                </td></tr>
                <tr><td style="padding:16px 32px 32px;font-size:15px;line-height:1.6;">
                    @yield('content')
                </td></tr>
            </table>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;">
                <tr><td align="center" style="padding:20px 32px;font-size:12px;line-height:1.6;color:{{ $c['muted'] }};">
                    @yield('footer')
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
