<!doctype html>
<html lang="{{ $store->locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') – {{ $store->name }}</title>
    <link rel="icon" href="/favicon.png">
    <link rel="stylesheet" href="/css/lumee-tokens.css">
    <link rel="stylesheet" href="{{ $theme->fontsUrl() }}">
    <style>:root { {!! $theme->cssVariables() !!} }</style>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; background: var(--ivory); color: var(--ink); font-family: var(--font-body); -webkit-font-smoothing: antialiased; }
        a { color: inherit; }
        input, select, textarea, button { font: inherit; color: inherit; }
        .co { display: grid; grid-template-columns: 1fr 1fr; min-height: 100vh; }
        .co-main { padding: 48px 56px 64px; display: flex; justify-content: flex-end; }
        .co-main > div { width: 100%; max-width: 560px; }
        .co-side { background: var(--rose-gold-50); border-left: 1px solid var(--border); padding: 48px 56px; }
        .co-side > div { max-width: 420px; position: sticky; top: 48px; }
        .logo { display: inline-block; font-family: var(--font-display); font-size: 28px; letter-spacing: .25em; text-transform: uppercase; color: var(--rose-gold-700); text-decoration: none; margin-bottom: 28px; }
        h2 { font-size: 17px; font-weight: 600; margin: 32px 0 14px; }
        .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .grid3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
        .stack { display: grid; gap: 12px; }
        .field { display: block; width: 100%; padding: 13px 14px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface); }
        .field:focus { outline: none; border-color: var(--rose-gold-500); box-shadow: 0 0 0 3px var(--rose-gold-100); }
        .field.invalid { border-color: var(--danger); }
        label.sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
        .options { border: 1px solid var(--border); border-radius: 10px; background: var(--surface); overflow: hidden; }
        .option { display: flex; align-items: center; gap: 12px; padding: 15px 16px; cursor: pointer; }
        .option + .option { border-top: 1px solid var(--border); }
        .option:has(input:checked) { background: var(--rose-gold-50); }
        .option input { accent-color: var(--rose-gold-500); width: 18px; height: 18px; margin: 0; }
        .option .grow { flex: 1; }
        .option .sub { display: block; font-size: 12px; color: var(--ink-muted); margin-top: 2px; }
        .btn { display: block; width: 100%; padding: 17px; border-radius: 999px; border: none; background: var(--gradient-metal); color: var(--on-primary); font-size: 14px; letter-spacing: .2em; text-transform: uppercase; cursor: pointer; margin-top: 28px; }
        .btn:hover { filter: brightness(1.05); }
        .secure { text-align: center; font-size: 12px; color: var(--ink-muted); margin-top: 14px; }
        .back { display: inline-block; margin-top: 18px; font-size: 13px; color: var(--rose-gold-700); }
        .errors { background: #FBEFF0; border: 1px solid #F0CDD1; color: var(--danger); border-radius: 10px; padding: 12px 16px; font-size: 14px; margin-bottom: 8px; }
        .errors ul { margin: 0; padding-left: 18px; }
        .item { display: flex; gap: 14px; align-items: center; margin-bottom: 16px; }
        .item .thumb { position: relative; width: 64px; height: 64px; border-radius: 10px; background: var(--surface); border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; flex: none; }
        .item .thumb img { width: 100%; height: 100%; object-fit: cover; border-radius: 10px; }
        .item .thumb img.ph { width: 60%; height: auto; opacity: .5; }
        .item .badge { position: absolute; top: -8px; right: -8px; min-width: 20px; height: 20px; border-radius: 10px; background: var(--ink-muted); color: #fff; font-size: 11px; display: flex; align-items: center; justify-content: center; padding: 0 6px; }
        .item .name { flex: 1; font-size: 14px; }
        .item .name small { display: block; color: var(--ink-muted); }
        .row { display: flex; justify-content: space-between; font-size: 14px; margin: 10px 0; }
        .row.discount { color: var(--rose-gold-700); }
        .consent { display: flex; gap: 8px; align-items: center; font-size: 13px; color: var(--ink-muted); margin: 10px 0 4px; cursor: pointer; }
        .row.discount-note { font-size: 12px; color: var(--ink-muted); margin-top: -4px; }
        .code-tag { font-size: 11px; letter-spacing: .08em; padding: 1px 8px; border-radius: 999px; background: var(--rose-gold-100); margin-left: 4px; }
        .row.total { font-size: 20px; font-weight: 600; border-top: 1px solid var(--border); padding-top: 18px; margin-top: 18px; }
        .row.total small { font-size: 12px; color: var(--ink-muted); font-weight: 400; margin-right: 6px; }
        .money { font-variant-numeric: tabular-nums; }
        @media (max-width: 900px) {
            .co { grid-template-columns: 1fr; }
            .co-side { order: -1; border-left: none; border-bottom: 1px solid var(--border); padding: 24px 16px; }
            .co-main { padding: 24px 16px 48px; }
            .grid3 { grid-template-columns: 1fr 1fr; }
        }
    </style>
    @stack('head')
    {{-- Child themes (e.g. Fresh) restyle here: printed after page styles so they win --}}
    @stack('theme-overrides')
    @include('storefront.tracking')
</head>
<body>
    @yield('content')
</body>
</html>
