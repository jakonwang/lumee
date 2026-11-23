{{--
    Bold theme — dense, high-energy promo style.
    Reuses Aurora's markup (it "extends" Aurora in config/themes.php) and only restyles it:
    condensed uppercase headings, square corners, black buttons, the accent color on sale badges and prices.
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
        --tracking-wide: .08em;
        --shadow-soft: none;
    }
    .display { font-weight: 700; text-transform: uppercase; letter-spacing: .01em; line-height: 1.05; }
    .eyebrow { color: var(--gem-blush); font-weight: 700; letter-spacing: .1em; }

    /* Header: black announcement bar, tight nav */
    .announce { background: #111; color: #fff; letter-spacing: .08em; font-weight: 600; font-size: 12px; }
    .logo { text-transform: uppercase; letter-spacing: .04em; font-weight: 700; font-size: 26px; color: var(--ink); }
    @media (max-width: 520px) { .logo { font-size: 20px; } }
    nav.main { letter-spacing: .06em; font-size: 13px; font-weight: 600; gap: 22px; }

    /* Buttons: black blocks, square */
    .btn { border-radius: 0; background: var(--ink); color: #fff; letter-spacing: .1em; font-weight: 700; padding: 14px 32px; }
    .btn:hover { filter: none; background: var(--gem-blush); }
    .btn-ghost { background: #fff; color: var(--ink); border: 2px solid var(--ink); }
    .btn-ghost:hover { background: var(--ink); color: #fff; }

    /* Hero: big type on black when there's no image */
    .hero:not(.has-img) { background: #111; color: #fff; }
    .hero:not(.has-img)::before { display: none; }
    .hero h1 { font-size: clamp(40px, 8vw, 88px); text-transform: uppercase; }
    .hero:not(.has-img) .btn, .hero.has-img .btn { background: #fff; color: #111; }
    .hero .btn:hover { background: var(--gem-blush); color: #fff; }

    /* Product cards: dense grid, red sale badges and prices */
    .grid { gap: 14px; }
    .card .img { border-radius: 0; }
    .card h3 { font-size: 13px; font-weight: 500; margin-top: 8px; }
    .card .price { font-weight: 700; }
    .card .price .was { font-weight: 400; }
    .card .badge { border-radius: 0; background: var(--gem-blush); color: #fff; text-transform: uppercase; letter-spacing: .04em; font-weight: 700; }
    .pill span, .field, .flash, .f-chip, .f-values.chips .f-check { border-radius: 0 !important; }

    .coll-name { text-transform: uppercase; font-weight: 700; letter-spacing: .04em; }
    .features-section { background: #F4F4F4; }
    .feature h3 { text-transform: uppercase; font-weight: 700; }
    .drawer > ul > li > a { text-transform: uppercase; font-weight: 700; }
    footer.site { background: #111; color: #bbb; }
    footer .tagline, .footer-links { color: #fff; }
</style>
@endpush
