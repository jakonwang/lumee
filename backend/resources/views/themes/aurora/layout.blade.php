<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\I18n\Localization::dir() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php
        // Canonical = the store's primary domain + path (never a preview or alternate host)
        // Canonical = the store's primary domain + this language's path (never a preview or alternate host)
        $l10n = app(\App\I18n\Localization::class);
        $seoPath = '/'.ltrim(preg_replace('#^'.preg_quote(ltrim($l10n->prefix(), '/'), '#').'(/|$)#', '', request()->path() === '/' ? '' : request()->path()), '/');
        $seoQuery = (int) request()->query('page') > 1 ? '?page='.(int) request()->query('page') : '';
        $seoCanonical = $store->storefrontUrl().$l10n->url($seoPath).$seoQuery;
        $seoLanguages = \App\Markets\Storefront::languages($store);   // this market's languages (or the store's)
        $seoAlternates = \App\Markets\Storefront::alternates($store, $seoPath);
        $seoAbs = fn ($u) => $u && str_starts_with($u, '/') && ! str_starts_with($u, '//') ? $store->storefrontUrl().$u : $u;
        $seoImage = $seoAbs(trim($__env->yieldContent('og_image')));
    @endphp
    <title>@yield('title', $store->name)</title>
    <meta name="description" content="@yield('description', $store->name)">
    @if ($theme->preview || $__env->hasSection('noindex'))<meta name="robots" content="noindex">@endif
    <link rel="canonical" href="{{ $seoCanonical }}">
    {{-- Same page in every published language and every market (search engines show shoppers the right one) --}}
    @foreach ($seoAlternates as $alt)
        <link rel="alternate" hreflang="{{ $alt['hreflang'] }}" href="{{ $alt['href'].$seoQuery }}">
    @endforeach
    <meta property="og:site_name" content="{{ $store->name }}">
    <meta property="og:type" content="@yield('og_type', 'website')">
    <meta property="og:url" content="{{ $seoCanonical }}">
    <meta property="og:title" content="@yield('title', $store->name)">
    <meta property="og:description" content="@yield('description', $store->name)">
    @if ($seoImage)<meta property="og:image" content="{{ $seoImage }}">@endif
    <meta name="twitter:card" content="{{ $seoImage ? 'summary_large_image' : 'summary' }}">
    <link rel="icon" href="/favicon.png">
    <link rel="stylesheet" href="/css/lumee-tokens.css">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="{{ $theme->fontsUrl() }}">
    {{-- Merchant colors & fonts. Values are validated hex colors / whitelisted font names (ThemeService). --}}
    <style>:root { {!! $theme->cssVariables() !!} }</style>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; background: var(--ivory); color: var(--ink); font-family: var(--font-body); -webkit-font-smoothing: antialiased; }
        /* Blocks (any page): spacing and headings */
        .section { padding-block: 72px; }
        .section-head { text-align: center; margin-bottom: 40px; }
        .section-head h2 { font-size: clamp(28px, 4vw, 38px); margin: 8px 0 0; }
        .prose { color: var(--ink-muted); line-height: 1.8; white-space: pre-line; }
        @media (max-width: 720px) { .section { padding-block: 48px; } }
        /* Blocks shown only on computers / only on phones (block setting "visibility") */
        .blk { display: contents; }
        @media (max-width: 720px) { .blk.vis-desktop { display: none; } }
        @media (min-width: 721px) { .blk.vis-mobile { display: none; } }
        /* Right-to-left pages: merchant text in another script (e.g. English perks on an Arabic site) keeps its own direction */
        [dir="rtl"] .perks > *, [dir="rtl"] .announcement, [dir="rtl"] .desc { unicode-bidi: plaintext; }
        a { color: inherit; text-decoration: none; }
        img { max-width: 100%; }
        .wrap { max-width: 1200px; margin: 0 auto; padding: 0 16px; }
        .announce { display: block; background: var(--rose-gold-100); color: var(--rose-gold-700); text-align: center; font-size: 12px; letter-spacing: .2em; padding: 8px 16px; text-transform: uppercase; }
        a.announce:hover { text-decoration: underline; }
        header.site { border-bottom: 1px solid var(--border); background: var(--surface); position: sticky; top: 0; z-index: 10; }
        header.site .wrap { display: flex; align-items: center; justify-content: space-between; min-height: 72px; padding-block: 10px; gap: 16px; }
        .logo { font-family: var(--font-display); font-size: 28px; letter-spacing: .25em; text-transform: uppercase; color: var(--rose-gold-700); line-height: 1.1; }
        .logo img { display: block; width: auto; max-width: 220px; object-fit: contain; }
        nav.main { display: flex; gap: 28px; font-size: 13px; letter-spacing: .15em; text-transform: uppercase; }
        nav.main a:hover { color: var(--rose-gold-500); }
        .eyebrow { font-size: 11px; letter-spacing: var(--tracking-wide); text-transform: uppercase; color: var(--rose-gold-500); }
        .display { font-family: var(--font-display); font-weight: 600; }
        .btn { display: inline-block; padding: 14px 36px; border-radius: var(--radius-pill); background: var(--gradient-metal); color: var(--on-primary); font-size: 13px; letter-spacing: .2em; text-transform: uppercase; border: none; cursor: pointer; }
        .btn:hover { filter: brightness(1.05); }
        footer.site { margin-top: 96px; border-top: 1px solid var(--border); padding: 48px 0; text-align: center; color: var(--ink-muted); font-size: 13px; }
        footer .rule { color: var(--rose-gold-300); letter-spacing: .3em; margin-bottom: 12px; }
        footer .tagline { font-family: var(--font-display); font-size: 20px; color: var(--ink); margin-bottom: 14px; }
        .btn-block { display: block; width: 100%; text-align: center; }
        .btn-ghost { background: none; color: var(--rose-gold-700); border: 1px solid var(--rose-gold-300); }
        .header-right { display: flex; align-items: center; gap: 28px; }
        .bag { position: relative; display: flex; color: var(--ink); }
        .bag:hover { color: var(--rose-gold-500); }
        .bag-count { position: absolute; top: -6px; right: -9px; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 9px; background: var(--rose-gold-500); color: var(--on-primary); font-size: 11px; display: flex; align-items: center; justify-content: center; }
        .flash { margin-top: 20px; padding: 12px 16px; border-radius: 10px; background: var(--rose-gold-50); border: 1px solid var(--border); color: var(--rose-gold-700); font-size: 14px; }
        .flash-error { background: #FBEFF0; border-color: #F0CDD1; color: var(--danger); }
        .money { font-variant-numeric: tabular-nums; }
        .lang-switch { display: flex; justify-content: center; flex-wrap: wrap; gap: 6px 14px; margin-bottom: 14px; font-size: 13px; }
        .lang-switch a { color: var(--ink-muted); text-decoration: none; }
        .lang-switch a[aria-current] { color: var(--ink); font-weight: 600; }
        .currency-form { display: inline-flex; align-items: center; gap: 8px; margin-bottom: 16px; font-size: 13px; }
        .currency-form select { padding: 6px 10px; border-radius: 999px; border: 1px solid var(--border); background: var(--surface); color: var(--ink); }
        input, select, textarea { font: inherit; color: inherit; }
        .field { display: block; width: 100%; padding: 12px 14px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface); }
        .field:focus { outline: none; border-color: var(--rose-gold-500); box-shadow: 0 0 0 3px var(--rose-gold-100); }
        /* Desktop dropdowns: open on hover and on keyboard focus */
        .has-sub { position: relative; }
        .has-sub .caret { font-size: 9px; vertical-align: 2px; }
        .has-sub .sub {
            position: absolute; top: 100%; left: -18px; padding: 10px 0; min-width: 210px; margin-top: 8px;
            background: var(--surface); border: 1px solid var(--border); border-radius: 12px; box-shadow: var(--shadow-soft);
            display: none; flex-direction: column; text-transform: none; letter-spacing: .02em; font-size: 14px; z-index: 20;
        }
        .has-sub .sub::before { content: ''; position: absolute; left: 0; right: 0; top: -10px; height: 10px; }
        .has-sub:hover .sub, .has-sub:focus-within .sub { display: flex; }
        .has-sub .sub a { padding: 8px 18px; }
        .has-sub .sub a:hover { background: var(--rose-gold-50); }
        .menu-btn { display: none; background: none; border: none; padding: 6px; margin-inline-start: -6px; color: var(--ink); cursor: pointer; }
        .drawer-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.3); z-index: 40; }
        .drawer { position: fixed; top: 0; inset-inline-start: 0; bottom: 0; width: min(320px, 85vw); background: var(--surface); z-index: 41; padding: 56px 24px 24px; overflow-y: auto; box-shadow: 0 0 40px rgba(0,0,0,.15); }
        .drawer[hidden], .drawer-backdrop[hidden] { display: none; }
        .drawer-close { position: absolute; top: 12px; inset-inline-end: 14px; font-size: 28px; line-height: 1; background: none; border: none; color: var(--ink); cursor: pointer; padding: 4px 8px; }
        .drawer ul { list-style: none; margin: 0; padding: 0; }
        .drawer > ul > li { border-bottom: 1px solid var(--border); padding: 14px 0; }
        .drawer > ul > li > a { font-family: var(--font-display); font-size: 22px; }
        .drawer li ul { margin-top: 8px; padding-inline-start: 14px; }
        .drawer li ul a { display: block; padding: 6px 0; color: var(--ink-muted); }
        .footer-links { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px 28px; margin-bottom: 28px; color: var(--ink); }
        .footer-links a:hover { color: var(--rose-gold-500); }
        @media (max-width: 720px) {
            nav.main { display: none; }
            .menu-btn { display: flex; }
            header.site .wrap { position: relative; }
            .logo { font-size: 20px; letter-spacing: .18em; position: absolute; left: 50%; transform: translateX(-50%); max-width: calc(100% - 300px); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .logo img { max-width: 100%; max-height: 40px; }
            .header-right { gap: 18px; }
        }
        /* Phones: four icons leave no room for a centered name, so the logo sits beside the menu button and shrinks */
        @media (max-width: 520px) {
            header.site .wrap { gap: 12px; min-height: 60px; }
            .logo { position: static; transform: none; flex: 1; min-width: 0; max-width: none; font-size: 17px; letter-spacing: .1em; }
            .header-right { gap: 14px; }
        }
    </style>
    @stack('head')
    {{-- Child themes (e.g. Fresh) restyle here: printed after page styles so they win --}}
    @stack('theme-overrides')
    {{-- Theme settings › style (corners, buttons, cards, spacing): after the theme's own styles --}}
    <style>{!! $theme->styleCss() !!}</style>
    @include('storefront.tracking')
</head>
<body>
    @if ($theme->setting('header.announcement_enabled') && $theme->setting('header.announcement_text'))
        @if ($link = $theme->setting('header.announcement_link'))
            <a class="announce" href="{{ $link }}">{{ $theme->setting('header.announcement_text') }}</a>
        @else
            <div class="announce">{{ $theme->setting('header.announcement_text') }}</div>
        @endif
    @endif

    <header class="site">
        <div class="wrap">
            @if ($mainMenu)
                <button type="button" class="menu-btn" aria-label="{{ __('Open menu') }}" aria-controls="drawer" aria-expanded="false">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                </button>
            @endif
            <a class="logo" href="/" aria-label="{{ __(':store home', ['store' => $store->name]) }}">
                @if ($theme->logoUrl())
                    <img src="{{ $theme->logoUrl() }}" alt="{{ $store->name }}" style="height: {{ (int) $theme->setting('header.logo_height') }}px">
                @else
                    {{ $store->name }}
                @endif
            </a>
            <div class="header-right">
                <nav class="main" aria-label="{{ __('Main menu') }}">
                    @foreach ($mainMenu as $item)
                        @if ($item['children'])
                            <div class="has-sub">
                                <a href="{{ $item['href'] }}" aria-haspopup="true">{{ $item['title'] }} <span class="caret" aria-hidden="true">▾</span></a>
                                <div class="sub">
                                    @foreach ($item['children'] as $child)
                                        <a href="{{ $child['href'] }}">{{ $child['title'] }}</a>
                                    @endforeach
                                </div>
                            </div>
                        @else
                            <a href="{{ $item['href'] }}">{{ $item['title'] }}</a>
                        @endif
                    @endforeach
                </nav>
                <a class="bag" href="/search" aria-label="{{ __('Search') }}" title="{{ __('Search') }}">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                </a>
                <a class="bag wish-link" href="/wishlist" aria-label="{{ __('Wishlist') }}" title="{{ __('Wishlist') }}">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M12 20s-7-4.4-9.2-9A5 5 0 0 1 12 6a5 5 0 0 1 9.2 5C19 15.6 12 20 12 20Z"/></svg>
                    <span class="bag-count wish-count" hidden></span>
                </a>
                <a class="bag" href="{{ $customer ? '/account' : '/account/login' }}" aria-label="{{ $customer ? __('My account') : __('Log in') }}" title="{{ $customer ? __('My account') : __('Log in') }}">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/></svg>
                </a>
                <a class="bag" href="/cart" aria-label="{{ trans_choice('Shopping bag, :count item|Shopping bag, :count items', $cartCount) }}">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M5 8h14l-1 12H6L5 8Z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg>
                    @if ($cartCount > 0)<span class="bag-count">{{ $cartCount }}</span>@endif
                </a>
            </div>
        </div>
    </header>

    @if ($mainMenu)
        {{-- Mobile navigation drawer --}}
        <div class="drawer-backdrop" hidden></div>
        <nav id="drawer" class="drawer" aria-label="{{ __('Menu') }}" hidden>
            <button type="button" class="drawer-close" aria-label="{{ __('Close menu') }}">×</button>
            <ul>
                @foreach ($mainMenu as $item)
                    <li>
                        <a href="{{ $item['href'] }}">{{ $item['title'] }}</a>
                        @if ($item['children'])
                            <ul>
                                @foreach ($item['children'] as $child)
                                    <li><a href="{{ $child['href'] }}">{{ $child['title'] }}</a></li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
                <li><a href="{{ $customer ? '/account' : '/account/login' }}">{{ $customer ? __('My account') : __('Log in') }}</a></li>
            </ul>
        </nav>
        <script>
        (function () {
            const btn = document.querySelector('.menu-btn'), drawer = document.getElementById('drawer'),
                  backdrop = document.querySelector('.drawer-backdrop');
            function set(open) {
                drawer.hidden = backdrop.hidden = !open;
                btn.setAttribute('aria-expanded', String(open));
                document.body.style.overflow = open ? 'hidden' : '';
                if (open) drawer.querySelector('a, button').focus(); else btn.focus();
            }
            btn.addEventListener('click', function () { set(true); });
            drawer.querySelector('.drawer-close').addEventListener('click', function () { set(false); });
            backdrop.addEventListener('click', function () { set(false); });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !drawer.hidden) set(false); });
        })();
        </script>
    @endif

    {{-- Field-level errors shown next to their field (e.g. the discount code) are left out here --}}
    @php($pageErrors = collect($errors->getMessages())->except('discount_code')->flatten())
    @if (session('status') || session('error') || $pageErrors->isNotEmpty())
        <div class="wrap">
            <div class="flash {{ session('error') || $pageErrors->isNotEmpty() ? 'flash-error' : '' }}">
                {{ session('error') ?? ($pageErrors->first() ?? session('status')) }}
            </div>
        </div>
    @endif

    <main>@yield('content')</main>

    <footer class="site">
        <div class="wrap">
            @if ($theme->setting('footer.tagline'))
                <div class="tagline">{{ $theme->setting('footer.tagline') }}</div>
            @endif
            @if ($footerMenu)
                <nav class="footer-links" aria-label="{{ __('Footer') }}">
                    @foreach ($footerMenu as $item)
                        <a href="{{ $item['href'] }}">{{ $item['title'] }}</a>
                        @foreach ($item['children'] as $child)
                            <a href="{{ $child['href'] }}">{{ $child['title'] }}</a>
                        @endforeach
                    @endforeach
                </nav>
            @endif
            @if (count($seoLanguages) > 1)
                {{-- Language picker: absolute links so they are not re-prefixed --}}
                <nav class="lang-switch" aria-label="{{ __('Language') }}">
                    @foreach ($seoLanguages as $code)
                        <a href="{{ $store->storefrontUrl().$l10n->url($seoPath, $code) }}" hreflang="{{ $code }}" lang="{{ $code }}"
                           @if ($code === app()->getLocale()) aria-current="true" @endif>{{ \App\I18n\Localization::languages()[$code] }}</a>
                    @endforeach
                </nav>
            @endif
            @php($regions = \App\Markets\Storefront::regions($store, $seoPath))
            @if ($regions)
                {{-- Country/region: each market has its own prices and currency. Absolute URLs (not re-prefixed). --}}
                <div class="currency-form region-form">
                    <label for="region-select">{{ __('Country/region') }}</label>
                    <select id="region-select" onchange="location.href = this.value">
                        @foreach ($regions as $r)
                            <option value="{{ $r['href'] }}" @selected($r['current'])>{{ $r['label'] }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            @php($offered = \App\Currency\CurrencySettings::for($store)->offered())
            @if (count($offered) > 1)
                <form method="post" action="/currency" class="currency-form">
                    @csrf
                    <label for="currency-select">{{ __('Currency') }}</label>
                    <select id="currency-select" name="currency" onchange="this.form.submit()">
                        @foreach ($offered as $code)
                            <option value="{{ $code }}" @selected($px->currency() === $code)>{{ $code }}</option>
                        @endforeach
                    </select>
                    <noscript><button type="submit">{{ __('Change') }}</button></noscript>
                </form>
            @endif
            <div class="rule">— {{ mb_strtoupper($store->name) }} —</div>
            <div>&copy; {{ date('Y') }} {{ $store->name }}@if ($theme->setting('footer.show_powered_by')) · {{ __('Powered by Lumée') }} @endif</div>
        </div>
    </footer>

    @if ($theme->preview)
        @include($theme->view('partials.preview'))
    @endif
    @include('storefront.popup')
    @include('storefront.market-suggest', ['suggestion' => $theme->preview ? null : \App\Markets\Storefront::suggestion($store, $seoPath)])
    <style>
        .wish-link { position: relative; }
        .wish-btn { flex: none; width: 48px; border: 1px solid var(--border); border-radius: var(--radius-btn, 999px); background: transparent; color: var(--ink); cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
        .wish-btn:hover { border-color: var(--rose-gold-500); color: var(--rose-gold-500); }
        .wish-btn[aria-pressed="true"] { color: var(--rose-gold-500); border-color: var(--rose-gold-500); }
        .wish-btn[aria-pressed="true"] path { fill: currentColor; }
    </style>
    <script>
        // Wishlist: kept in this browser (per store); hearts on product pages, count in the header
        window.lumeeWishlist = (function () {
            const key = 'lm_wishlist_{{ $store->id }}';
            const read = () => { try { return JSON.parse(localStorage.getItem(key) || '[]').filter((h) => typeof h === 'string').slice(0, 60); } catch (e) { return []; } };
            const write = (list) => { try { localStorage.setItem(key, JSON.stringify(list.slice(0, 60))); } catch (e) {} paint(); };
            function paint() {
                const list = read();
                document.querySelectorAll('.wish-count').forEach((el) => { el.textContent = list.length; el.hidden = !list.length; });
                document.querySelectorAll('[data-wish]').forEach((b) => b.setAttribute('aria-pressed', list.includes(b.dataset.wish) ? 'true' : 'false'));
            }
            document.addEventListener('click', (e) => {
                const b = e.target.closest('[data-wish]');
                if (!b) return;
                e.preventDefault();
                const list = read();
                const h = b.dataset.wish;
                write(list.includes(h) ? list.filter((x) => x !== h) : [h, ...list]);
            });
            document.addEventListener('DOMContentLoaded', paint);
            return { read, write, paint };
        })();
    </script>
    {{-- Block scripts (slideshow, countdowns, tabs…), after the page so they find their elements --}}
    @stack('scripts')
</body>
</html>
