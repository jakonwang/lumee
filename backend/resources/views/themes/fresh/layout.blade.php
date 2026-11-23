{{--
    Fresh theme — clean green & white.
    Reuses Aurora's markup (it "extends" Aurora in config/themes.php) and only restyles it:
    sans-serif type, no all-caps letter-spacing, solid buttons, white surfaces with green accents.
    Colors come from the merchant's theme settings (--rose-gold-* are derived from "primary").
--}}
@extends('themes.aurora.layout')

@push('theme-overrides')
<style>
    :root {
        --gradient-metal: var(--rose-gold-500);
        --gradient-gem: none;
        --gem-lavender: var(--rose-gold-500);
        --gem-aqua: var(--rose-gold-300);
        --radius-card: 12px;
        --tracking-wide: .12em;
        --shadow-soft: 0 1px 3px rgba(16, 24, 20, .06);
    }
    .display { font-weight: 700; letter-spacing: -0.01em; }
    .eyebrow { font-weight: 600; }

    /* Header & navigation */
    .announce { text-transform: none; letter-spacing: 0; font-size: 13px; font-weight: 500; }
    header.site { border-bottom-color: var(--border); }
    .logo { text-transform: none; letter-spacing: -0.01em; font-weight: 700; font-size: 24px; color: var(--ink); }
    @media (max-width: 520px) { .logo { font-size: 19px; letter-spacing: -0.01em; } }
    nav.main { text-transform: none; letter-spacing: 0; font-size: 15px; font-weight: 500; }
    .has-sub .sub { border-radius: 10px; }

    /* Buttons */
    .btn { border-radius: 8px; text-transform: none; letter-spacing: 0; font-size: 15px; font-weight: 600; padding: 13px 30px; }
    .btn:hover { filter: brightness(.94); }
    .btn-ghost { background: var(--surface); color: var(--ink); border: 1px solid var(--border); }
    .btn-ghost:hover { border-color: var(--rose-gold-500); color: var(--rose-gold-700); filter: none; }

    /* Hero: soft green tint instead of the gem glow */
    .hero:not(.has-img) { background: var(--rose-gold-50); }
    .hero:not(.has-img)::before { display: none; }
    .hero h1 { letter-spacing: -0.02em; font-weight: 700; }

    /* Product cards */
    .card .img { border: 1px solid var(--border); background: var(--surface-muted); }
    .card h3 { font-weight: 500; }
    .card .price { color: var(--ink); font-weight: 600; }
    .card .badge { text-transform: none; letter-spacing: 0; font-weight: 600; border-radius: 6px; }

    /* Product page option buttons */
    .pill span { border-radius: 8px; }

    /* Collections */
    .coll-img { border: 1px solid var(--border); }
    .coll-name { font-weight: 600; font-size: 18px; }

    /* Features strip */
    .features-section { background: var(--rose-gold-50); }
    .feature h3 { font-weight: 700; font-size: 18px; }

    /* Footer & mobile menu */
    footer .rule { letter-spacing: .12em; }
    .drawer > ul > li > a { font-weight: 600; font-size: 19px; }
    .flash { border-radius: 8px; }
    .field { border-radius: 8px; }
</style>
@endpush
