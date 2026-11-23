{{--
    Luxe theme — minimal premium style.
    Reuses Aurora's markup (it "extends" Aurora in config/themes.php) and only restyles it:
    generous white space, hairline borders, high-contrast serif headings, outlined buttons.
--}}
@extends('themes.aurora.layout')

@push('theme-overrides')
<style>
    :root {
        --gradient-metal: var(--ink);
        --gradient-gem: none;
        --gem-lavender: var(--ink);
        --gem-aqua: var(--rose-gold-300);
        --radius-card: 0px;
        --radius-pill: 0px;
        --tracking-wide: .28em;
        --shadow-soft: none;
    }
    .display { font-weight: 400; letter-spacing: 0; }
    .eyebrow { color: var(--ink-muted); letter-spacing: .3em; font-weight: 400; }

    .announce { background: transparent; color: var(--ink); border-bottom: 1px solid var(--border); letter-spacing: .24em; font-size: 11px; }
    header.site .wrap { min-height: 84px; }
    .logo { letter-spacing: .3em; font-weight: 400; color: var(--ink); }
    nav.main { letter-spacing: .24em; font-size: 12px; font-weight: 400; gap: 36px; }

    /* Buttons: thin outlines that fill on hover */
    .btn { border-radius: 0; background: transparent; color: var(--ink); border: 1px solid var(--ink); letter-spacing: .28em; font-size: 12px; font-weight: 400; padding: 15px 40px; }
    .btn:hover { filter: none; background: var(--ink); color: var(--ivory); }
    .hero.has-img .btn { color: #fff; border-color: #fff; }
    .hero.has-img .btn:hover { background: #fff; color: var(--ink); }
    .btn-ghost { border-color: var(--border); }

    .hero:not(.has-img) { background: var(--surface-muted); }
    .hero:not(.has-img)::before { display: none; }
    .hero h1 { font-weight: 400; }

    /* More air around sections and products */
    .grid { gap: 40px 28px; }
    .card .img { border-radius: 0; background: var(--surface-muted); }
    .card h3 { font-weight: 400; letter-spacing: .04em; margin-top: 16px; }
    .card .price { font-weight: 400; color: var(--ink-muted); }
    .card .badge { border-radius: 0; background: var(--surface); color: var(--ink); border: 1px solid var(--ink); text-transform: uppercase; letter-spacing: .18em; font-weight: 400; font-size: 10px; }
    .pill span, .field, .flash, .f-chip, .f-values.chips .f-check { border-radius: 0 !important; }

    .coll-name { font-family: var(--font-display); font-weight: 400; font-size: 22px; }
    .features-section { background: transparent; border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); }
    .feature h3 { font-family: var(--font-display); font-weight: 400; }
    footer.site { border-top: 1px solid var(--border); }
    .drawer > ul > li > a { font-weight: 400; letter-spacing: .06em; }
</style>
@endpush
