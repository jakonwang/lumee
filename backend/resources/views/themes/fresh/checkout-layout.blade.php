{{-- Fresh theme checkout: Aurora's checkout layout, restyled (see themes/fresh/layout.blade.php) --}}
@extends('themes.aurora.checkout-layout')

@push('theme-overrides')
<style>
    :root { --gradient-metal: var(--rose-gold-500); }
    .logo { text-transform: none; letter-spacing: -0.01em; font-weight: 700; font-size: 24px; color: var(--ink); }
    .btn { border-radius: 8px; text-transform: none; letter-spacing: 0; font-size: 16px; font-weight: 600; }
    .btn:hover { filter: brightness(.94); }
    .field, .options { border-radius: 8px; }
    .co-side { background: var(--surface-muted); }
</style>
@endpush
